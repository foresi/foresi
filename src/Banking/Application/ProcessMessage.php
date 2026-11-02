<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

use App\Banking\Domain\InboundStatus;
use App\Banking\Domain\MessageTrust;
use App\Invoicing\Application\ConflictRetry;
use App\Party\Application\FirmBankAccounts;
use App\Party\Application\TransactionalWork;
use App\Party\Domain\FirmBankAccount;
use App\Party\Domain\Iban;
use App\Party\Domain\InvalidIban;
use App\Party\Domain\LegalEntity;
use Psr\Clock\ClockInterface;

/**
 * Turns one stored inbound message into movements, and pays invoices from them
 * when the message is trusted. All of it happens in one transaction that starts
 * by claiming the message, so a message is processed once however often it is
 * delivered, and a run that fails leaves nothing behind but the attempt.
 *
 * In order: the raw file must be the one stored; the parser is the one parser
 * that knows the mail; the receiving server's word on the sender decides the
 * trust, and a failed trust rejects the message unread; the parser reads it; every
 * movement must be booked on an active account of the firm the message came to
 * (another account of that firm is fine, as a bank may send all notices to one
 * address); then the movements are recorded and each incoming one paired when
 * the rules leave one answer. An unverified message is recorded and never pays
 * anything by itself.
 *
 * Failures a later parser may fix, or that a person decides on, are stored as
 * the message's status with their reason; only an unexpected error is thrown,
 * after the transaction was undone, so the queue tries again.
 */
final readonly class ProcessMessage
{
    /** The raw file is missing or not the one stored. */
    public const string RAW_CHANGED = 'raw_changed';
    /** The stored bytes are not a mail. */
    public const string UNREADABLE = 'unreadable';
    /** The bank's mail failed its authentication checks. */
    public const string UNTRUSTED = 'untrusted';
    /** A movement is booked on an account that is not an active one of the firm. */
    public const string ACCOUNT_MISMATCH = 'account_mismatch';

    /** What a message is processed from: as stored, or waiting for a parser. */
    private const array PROCESSABLE = [InboundStatus::Received, InboundStatus::Failed, InboundStatus::NotBankMail];

    public function __construct(
        private InboundMessages $messages,
        private InboundFiles $files,
        private MailReader $reader,
        private BankMessageParsers $parsers,
        private MailAuthentication $authentication,
        private FirmBankAccounts $accounts,
        private RecordMovement $record,
        private PairMovement $pairing,
        private PendingChanges $pending,
        private TransactionalWork $work,
        private ConflictRetry $retry,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Processes a message that was stored, failed or found no parser. A message
     * that stands elsewhere, processed already, say, is left as it is.
     *
     * @return InboundStatus where the message stands afterwards
     *
     * @throws UnknownInboundMessage also for a message of another tenant
     */
    public function process(string $messageId): InboundStatus
    {
        return $this->run($messageId, self::PROCESSABLE, null, null);
    }

    /**
     * Processes a message rejected for its trust as an unverified one, which
     * someone chose to record anyway: its movements are recorded and never paired
     * by themselves. Only AcceptRejectedMessage calls this.
     *
     * @throws UnknownInboundMessage also for a message of another tenant
     */
    public function processAccepted(string $messageId): InboundStatus
    {
        return $this->run($messageId, [InboundStatus::Rejected], self::UNTRUSTED, new TrustVerdict(MessageTrust::Unverified, TrustVerdict::ACCEPTED_BY_USER));
    }

    /**
     * @param non-empty-list<InboundStatus> $from
     */
    private function run(string $messageId, array $from, ?string $failure, ?TrustVerdict $accepted): InboundStatus
    {
        try {
            return $this->retry->run(fn (): InboundStatus => $this->work->run(function () use ($messageId, $from, $failure, $accepted): InboundStatus {
                $claimed = $this->messages->claim($messageId, $from, $failure);
                if (!$claimed instanceof ClaimedMessage) {
                    return $this->messages->find($messageId)?->status() ?? throw new UnknownInboundMessage();
                }
                $result = $this->result($claimed, $accepted);
                if ($accepted instanceof TrustVerdict) {
                    $this->messages->recordAccepted($messageId, $result->status);
                }
                $this->messages->finish($messageId, $result, \DateTimeImmutable::createFromInterface($this->clock->now()));

                return $result->status;
            }));
        } catch (UnknownInboundMessage $unknown) {
            throw $unknown;
        } catch (\Throwable $failed) {
            $this->countFailedAttempt($messageId);

            throw $failed;
        }
    }

    private function result(ClaimedMessage $message, ?TrustVerdict $accepted): ProcessingResult
    {
        try {
            $raw = $this->files->read($message->tenantId, $message->rawKey, $message->rawSha256);
        } catch (\RuntimeException $unreadable) {
            return new ProcessingResult(InboundStatus::Failed, failure: self::RAW_CHANGED, detail: $unreadable->getMessage());
        }
        try {
            $mail = $this->reader->read($raw);
        } catch (\InvalidArgumentException) {
            return new ProcessingResult(InboundStatus::Failed, failure: self::UNREADABLE, detail: 'The stored message is not a mail.');
        }
        try {
            $parser = $this->parsers->for($mail);
        } catch (ParseFailed $ambiguous) {
            return new ProcessingResult(InboundStatus::Failed, parserId: $this->parsers->signature(), failure: $ambiguous->reason->value, detail: $ambiguous->detail);
        }
        if (!$parser instanceof BankMessageParser) {
            return new ProcessingResult(InboundStatus::NotBankMail, parserId: $this->parsers->signature());
        }

        $verdict = $accepted ?? $this->authentication->verdict($mail, $parser->senderDomains());
        if (MessageTrust::Failed === $verdict->trust) {
            return ProcessingResult::parsedBy(InboundStatus::Rejected, $parser, $verdict, self::UNTRUSTED, $verdict->basis);
        }
        try {
            $parsed = $parser->parse($mail);
        } catch (ParseFailed $failed) {
            return ProcessingResult::parsedBy(InboundStatus::Failed, $parser, $verdict, $failed->reason->value, $failed->detail);
        }
        if ([] === $parsed->movements()) {
            return ProcessingResult::parsedBy(InboundStatus::Ignored, $parser, $verdict, detail: $parsed->ignoredReason());
        }

        $firm = $this->accounts->get($message->accountId)->firm();
        $accountIds = [];
        foreach ($parsed->movements() as $movement) {
            $account = $this->activeAccount($firm, $movement->ownAccount);
            if (!$account instanceof FirmBankAccount) {
                return ProcessingResult::parsedBy(InboundStatus::Rejected, $parser, $verdict, self::ACCOUNT_MISMATCH, 'A movement is booked on an account that is not an active account of the firm.');
            }
            $accountIds[] = $account->id();
        }

        $this->recordAndPair($message->id, $parsed->movements(), $accountIds, $verdict);

        return ProcessingResult::parsedBy(InboundStatus::Processed, $parser, $verdict);
    }

    /**
     * Records every movement first, so the second of two equal ones in one
     * message is told from a duplicate the same way as on its own, then pairs.
     * Each step is stored before the next reads it back by plain statements.
     *
     * @param list<ParsedMovement> $movements
     * @param list<string>         $accountIds by the index of the movement
     */
    private function recordAndPair(string $messageId, array $movements, array $accountIds, TrustVerdict $verdict): void
    {
        $recorded = [];
        foreach ($movements as $index => $movement) {
            $recorded[] = $this->record->record(MovementInput::of($movement, $accountIds[$index], $messageId), $verdict)->id();
            $this->pending->store();
        }
        foreach ($recorded as $movementId) {
            $this->pairing->auto($movementId);
            $this->pending->store();
        }
    }

    private function activeAccount(LegalEntity $firm, string $iban): ?FirmBankAccount
    {
        try {
            $account = $this->accounts->ofIban($firm, Iban::fromString($iban)->toString());
        } catch (InvalidIban) {
            return null;
        }

        return $account instanceof FirmBankAccount && !$account->isArchived() ? $account : null;
    }

    /**
     * The attempt is counted outside the undone transaction. Should that fail as
     * well, the error that matters is the first one.
     */
    private function countFailedAttempt(string $messageId): void
    {
        try {
            $this->messages->countFailedAttempt($messageId);
        } catch (\Throwable) {
        }
    }
}
