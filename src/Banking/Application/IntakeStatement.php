<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Application;

use Foresi\Banking\Contract\BankStatement;
use Foresi\Banking\Contract\Event\StatementGapClosed;
use Foresi\Banking\Contract\Event\StatementGapDetected;
use Foresi\Banking\Contract\IntakeResult;
use Foresi\Banking\Contract\MovementChannel;
use Foresi\Banking\Contract\MovementProvenance;
use Foresi\Banking\Contract\StatementFile;
use Foresi\Banking\Contract\StatementIntake;
use Foresi\Banking\Contract\StatementRefused;
use Foresi\Banking\Domain\MovementState;
use Foresi\Banking\Domain\ReceivedStatement;
use Foresi\Banking\Domain\UnmatchedReason;
use Foresi\Party\Application\FirmBankAccounts;
use Foresi\Party\Application\InstanceFirm;
use Foresi\Party\Domain\FirmBankAccount;
use Foresi\Party\Domain\Iban;
use Foresi\Party\Domain\InvalidIban;
use Foresi\Shared\Application\ConflictRetry;
use Foresi\Shared\Application\TransactionalWork;
use Foresi\Shared\Contract\Event\EventRecorder;
use Psr\Clock\ClockInterface;

/**
 * The intake every channel submits to (StatementIntake has the rules). It runs in
 * one transaction under the lock of the account, so two statements of one account
 * are taken one after the other and the second sees the first.
 *
 * Every entry is recorded or confirmed first, each stored before the next is
 * looked at, so the second of two equal entries is told from a duplicate the same
 * way as on its own; then the new movements, and those whose trust a more trusted
 * source raised, are paired. The gaps are compared before and after the statement,
 * and each one opened or closed is an event.
 */
final readonly class IntakeStatement implements StatementIntake
{
    public function __construct(
        private FirmBankAccounts $accounts,
        private BankMovements $movements,
        private ReceivedStatements $statements,
        private RecordMovement $record,
        private PairMovement $pairing,
        private PendingChanges $pending,
        private TransactionalWork $work,
        private ConflictRetry $retry,
        private ClockInterface $clock,
        private EventRecorder $events,
        private InstanceFirm $firm,
    ) {
    }

    public function submit(BankStatement $statement, MovementProvenance $provenance, ?StatementFile $file = null): IntakeResult
    {
        $verdict = TrustVerdict::of($provenance);

        return $this->retry->run(fn (): IntakeResult => $this->work->run(function () use ($statement, $provenance, $verdict, $file): IntakeResult {
            $account = $this->activeAccount($statement->ownAccount)
                ?? throw new StatementRefused(StatementRefused::UNKNOWN_ACCOUNT, 'The statement is not of an active bank account of the firm.');
            $this->movements->lockAccount($account->id());
            $now = \DateTimeImmutable::createFromInterface($this->clock->now());

            $stored = null;
            $before = [];
            if (MovementChannel::Mail !== $provenance->channel) {
                $same = $this->sameFile($account->id(), $provenance->sourceId, $file);
                if ($same instanceof ReceivedStatement) {
                    return new IntakeResult($same->id(), [], [], \count($statement->entries));
                }
                $before = $this->gapsOf($account->id(), $provenance->sourceId);
                $stored = new ReceivedStatement($account, $statement, $provenance, $file?->sha256(), $file?->shortName(), $now);
                $this->statements->add($stored);
                $this->pending->store();
            }

            $recorded = [];
            $corroborated = [];
            $toPair = [];
            $known = 0;
            foreach ($statement->entries as $entry) {
                $input = MovementInput::of($entry, $account->id(), $provenance->inboundMessageId, $provenance->channel, $provenance->sourceId, $stored?->id());
                if (null !== $input->bankReference && $this->movements->referenceKnown($account->id(), $provenance->sourceId, $input->bankReference)) {
                    ++$known;
                    continue;
                }
                $candidates = $this->movements->corroborationCandidates($input);
                if (1 === \count($candidates)) {
                    $movement = $this->movements->lock($candidates[0]->id());
                    $waited = MovementState::Unmatched === $movement->state() && UnmatchedReason::Unverified === $movement->unmatchedReason();
                    $trustBefore = $movement->trust();
                    $movement->corroborate($provenance->sourceId, $input->bankReference, $stored?->id(), $provenance->inboundMessageId, $verdict->trust, $verdict->basis, $now);
                    $this->pending->store();
                    $corroborated[] = $movement->id();
                    if ($waited && $movement->trust()->isAbove($trustBefore)) {
                        $toPair[] = $movement->id();
                    }
                    continue;
                }
                $id = $this->record->record($input, $verdict)->id();
                $this->pending->store();
                $recorded[] = $id;
                $toPair[] = $id;
            }
            foreach ($toPair as $movementId) {
                $this->pairing->auto($movementId);
                $this->pending->store();
            }

            [$detected, $closed] = $stored instanceof ReceivedStatement ? $this->recordGaps($before, $this->gapsOf($account->id(), $provenance->sourceId), $account->id()) : [0, 0];

            return new IntakeResult($stored?->id(), $recorded, $corroborated, $known, $detected, $closed);
        }));
    }

    /**
     * The statement stored from the same file of the same source, which was taken in
     * whole already: a file uploaded twice is not a second statement.
     */
    private function sameFile(string $accountId, string $sourceId, ?StatementFile $file): ?ReceivedStatement
    {
        if (!$file instanceof StatementFile) {
            return null;
        }
        $hash = $file->sha256();
        foreach ($this->statements->chainOf($accountId, $sourceId) as $stored) {
            if ($hash === $stored->fileSha256()) {
                return $stored;
            }
        }

        return null;
    }

    /**
     * @return array<string, StatementGap> by key
     */
    private function gapsOf(string $accountId, string $sourceId): array
    {
        $gaps = [];
        foreach (StatementChain::gaps($this->statements->chainOf($accountId, $sourceId)) as $gap) {
            $gaps[$gap->key()] = $gap;
        }

        return $gaps;
    }

    /**
     * @param array<string, StatementGap> $before
     * @param array<string, StatementGap> $after
     *
     * @return array{int, int} how many gaps it opened and closed
     */
    private function recordGaps(array $before, array $after, string $accountId): array
    {
        $firmId = $this->firm->get()->id();
        $opened = array_diff_key($after, $before);
        $closed = array_diff_key($before, $after);
        foreach ($opened as $gap) {
            $this->events->record(new StatementGapDetected($gap->statement->id(), $gap->previous?->id(), $firmId, $accountId, $gap->kind));
        }
        foreach ($closed as $gap) {
            $this->events->record(new StatementGapClosed($gap->statement->id(), $gap->previous?->id(), $firmId, $accountId, $gap->kind));
        }

        return [\count($opened), \count($closed)];
    }

    private function activeAccount(string $iban): ?FirmBankAccount
    {
        try {
            $account = $this->accounts->ofIban(Iban::fromString($iban)->toString());
        } catch (InvalidIban) {
            return null;
        }

        return $account instanceof FirmBankAccount && !$account->isArchived() ? $account : null;
    }
}
