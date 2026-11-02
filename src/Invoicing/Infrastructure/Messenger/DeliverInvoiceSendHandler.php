<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Messenger;

use App\Invoicing\Application\ConflictRetry;
use App\Invoicing\Application\DeliverInvoiceSend;
use App\Invoicing\Application\DocumentLinks;
use App\Invoicing\Application\FirmInvoicingSettings;
use App\Invoicing\Application\InvoiceBalances;
use App\Invoicing\Application\InvoiceSends;
use App\Invoicing\Application\InvoiceStore;
use App\Invoicing\Application\LinkedMailBody;
use App\Invoicing\Application\LinkTokens;
use App\Invoicing\Application\MailGateway;
use App\Invoicing\Application\MailMessage;
use App\Invoicing\Application\MailRefused;
use App\Invoicing\Application\MailServers;
use App\Invoicing\Application\ReminderSettings;
use App\Invoicing\Application\SendDraft;
use App\Invoicing\Application\SendRecipient;
use App\Invoicing\Application\UndrawableDocument;
use App\Invoicing\Application\UnknownInvoice;
use App\Invoicing\Application\UnknownInvoiceSend;
use App\Invoicing\Application\UnsupportedCountry;
use App\Invoicing\Domain\FirmMailServer;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\InvoiceSend;
use App\Invoicing\Domain\LinkAudience;
use App\Invoicing\Domain\SendPurpose;
use App\Invoicing\Domain\SendStatus;
use App\Party\Application\ContactSettingsForFirm;
use App\Party\Application\TransactionalWork;
use App\Party\Application\UnknownLegalEntity;
use App\Party\Domain\PartyEmail;
use Doctrine\ORM\EntityNotFoundException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Delivers what the request froze, in the tenant of the message, in steps that
 * each leave a record, so a delivery that stops anywhere is finished later and a
 * mail the server accepted is never sent again:
 *
 *  1. A short unit claims the send. A claim younger than the lease belongs to a
 *     worker still at it: the message comes back when the lease ends, without
 *     using a retry (WaitForLeaseMiddleware).
 *  2. The server is called outside any transaction.
 *  3. Its acceptance is recorded on the send alone, at once.
 *  4. The invoice is marked delivered. If that fails, the send stays claimed and
 *     sent, and the retry only finishes this step.
 *  5. The firm's copy goes last, once: its outcome counts as unknown before it
 *     leaves, so a worker dying after the server took it never sends it twice.
 *
 * Only a worker dying between steps 2 and 3 can repeat the customer's mail, once,
 * with the same Message-ID. A temporary refusal is counted and thrown again for
 * the queue to retry; a permanent one fails the send at once. MarkSendFailed
 * fails a queued send when the retries run out and the send has used its tries;
 * RequeueStuckSends picks up a claim that no worker came back to.
 *
 * The lease outlasts the work it guards: the gateway ends each conversation with
 * the mail server within SymfonyMailGateway::DEADLINE_SECONDS, and one claim
 * covers at most two, the customer's mail and the copy.
 *
 * A reminder may have lost its reason while it waited: before it leaves, under the
 * locks of the invoice and the send, it is cancelled when the invoice went to the
 * trash or owes nothing any more. One the reminder run queued is also cancelled
 * when the firm switched reminders off or the contact is no longer reminded, and
 * fails visibly when the contact has no address left to send it to; one a person
 * sent goes as they asked. The check runs on the claimed send, after the wait for
 * another worker's lease and before the link is checked; when it cannot be made,
 * it counts as one of the send's tries, as a failure to hand the mail over does.
 */
#[AsMessageHandler]
final readonly class DeliverInvoiceSendHandler
{
    public const string LEASE = '5 minutes';
    private const string NO_SERVER = 'The firm has no mail server: add one in the e-mail settings.';
    private const string UNBUILDABLE = 'The message could not be built from the stored send.';
    private const string UNEXPECTED = 'The message could not be handed to the mail server.';
    private const string COPY_UNKNOWN = 'The copy was handed to the mail server, but its answer was not recorded.';
    private const string LINK_CHANGED = 'The link of this send no longer opens: the application secret changed after it was queued. Send it again.';
    private const string NO_RECIPIENT = 'The contact has no address to send the reminder to any more.';
    private const string CHECK_FAILED = 'Before the reminder left, it could not be checked whether its invoice is still unpaid.';
    /** Seconds past the end of a lease before a waiting message tries again. */
    private const int GRACE = 5;

    public function __construct(
        private InvoiceSends $sends,
        private InvoiceStore $invoices,
        private MailServers $servers,
        private MailGateway $gateway,
        private FirmInvoicingSettings $settings,
        private LinkTokens $tokens,
        private DocumentLinks $links,
        private TransactionalWork $work,
        private ConflictRetry $retry,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private InvoiceBalances $balances,
        private ReminderSettings $reminders,
        private ContactSettingsForFirm $contacts,
        private SendDraft $drafts,
    ) {
    }

    public function __invoke(DeliverInvoiceSend $message): void
    {
        $id = $message->sendId;
        try {
            $owed = $this->claim($id);
        } catch (UnknownInvoiceSend) {
            $this->logger->warning('No send {send} in the tenant of the message; nothing was sent.', ['send' => $id]);

            return;
        }
        if (!$owed instanceof InvoiceSend) {
            return;
        }
        $server = $this->servers->for($owed->invoice()->firm());
        if (SendStatus::Queued === $owed->status() && SendPurpose::Reminder === $owed->purpose()) {
            try {
                $stopped = $this->stoppedBeforeLeaving($id, $owed->invoice()->id(), null === $owed->createdBy());
            } catch (UnknownInvoice|UnknownLegalEntity|UnsupportedCountry|UndrawableDocument|EntityNotFoundException $unanswerable) {
                // What a retry would find the same: the reminder fails at once, in the log, and no try is wasted.
                $this->unit(function () use ($id): void {
                    $send = $this->sends->lock($id);
                    $send->recordAttempt($this->now(), self::CHECK_FAILED, code: MailRefused::CHECK_FAILED);
                    $send->fail($this->now(), self::CHECK_FAILED, false, MailRefused::CHECK_FAILED);
                });
                $this->logger->warning('The reminder {send} failed: it could not be checked before it left ({reason}).', ['send' => $id, 'reason' => $unanswerable::class]);

                return;
            } catch (\Throwable $failure) {
                // One of the send's own tries, and the claim released, so the retry
                // checks again and MarkSendFailed can give up once the tries are used.
                $this->unit(function () use ($id): void {
                    $this->sends->lock($id)->recordAttempt($this->now(), self::CHECK_FAILED, code: MailRefused::CHECK_FAILED);
                });

                throw $failure;
            }
            if ($stopped) {
                return;
            }
        }
        if (SendStatus::Queued === $owed->status()) {
            $this->deliver($owed, $server);
        }
        $send = $this->recordDelivered($id, $owed->invoice()->id());
        if ($send->isCopyOwed()) {
            $this->deliverCopy($send, $server);
        }
    }

    /**
     * @return InvoiceSend|null the send, claimed, when something is owed
     *
     * @throws SendLeaseHeld      while another worker holds the claim
     * @throws UnknownInvoiceSend for a send of another tenant
     */
    private function claim(string $id): ?InvoiceSend
    {
        return $this->unit(function () use ($id): ?InvoiceSend {
            $send = $this->sends->lock($id);
            $now = $this->now();
            $owed = SendStatus::Queued === $send->status() || (SendStatus::Sent === $send->status() && ($this->deliveryOwed($send) || $send->isCopyOwed()));
            if (!$owed) {
                $send->release();

                return null;
            }
            $leaseEnds = $send->claimedAt()?->modify('+'.self::LEASE);
            if ($leaseEnds instanceof \DateTimeImmutable && $leaseEnds > $now) {
                throw new SendLeaseHeld(($leaseEnds->getTimestamp() - $now->getTimestamp() + self::GRACE) * 1000);
            }
            $send->claim($now);

            return $send;
        });
    }

    /**
     * Whether a claimed reminder ends here instead of going out: cancelled, or
     * failed for want of an address. The invoice is locked first, in the order
     * trash takes them, and every answer is read now, not when it was queued.
     */
    private function stoppedBeforeLeaving(string $id, string $invoiceId, bool $automatic): bool
    {
        return $this->unit(function () use ($id, $invoiceId, $automatic): bool {
            $invoice = $this->invoices->lock($invoiceId);
            $cancelled = $invoice->isTrashed() || !$this->owes($invoice) || ($automatic && !$this->stillReminded($invoice));
            // Drawing the dialog's recipients reads the document, which starts the entity manager afresh: the send is locked after.
            $unreachable = !$cancelled && $automatic && !$this->hasRecipient($invoice);
            $send = $this->sends->lock($id);
            if (SendStatus::Queued !== $send->status()) {
                $send->release();

                return true;
            }
            if ($cancelled) {
                $send->cancel($this->now());
                $send->release();
                $this->logger->info('The reminder {send} was cancelled before it left: its invoice {invoice} is no longer to be reminded.', ['send' => $id, 'invoice' => $invoiceId]);

                return true;
            }
            if ($unreachable) {
                $send->fail($this->now(), self::NO_RECIPIENT, false, MailRefused::NO_RECIPIENT);
                $this->logger->warning('The reminder {send} failed: the contact of invoice {invoice} has no address to send it to.', ['send' => $id, 'invoice' => $invoiceId]);

                return true;
            }

            return false;
        });
    }

    private function owes(Invoice $invoice): bool
    {
        $outstanding = $this->balances->outstanding([$invoice->id()])[$invoice->id()];

        return !$outstanding->isZero() && !$outstanding->isNegative();
    }

    private function stillReminded(Invoice $invoice): bool
    {
        $firm = $invoice->firm();

        return $this->reminders->for($firm)->enabled() && $this->contacts->defaultsFor($invoice->customerParty(), $firm)->sendReminders;
    }

    /**
     * An address in To that the send dialog would tick, as the run picked them.
     */
    private function hasRecipient(Invoice $invoice): bool
    {
        return array_any($this->drafts->for($invoice)->recipients, static fn (SendRecipient $recipient): bool => SendRecipient::TO === $recipient->field && $recipient->checked);
    }

    /**
     * Sent, but the invoice does not know it yet.
     */
    private function deliveryOwed(InvoiceSend $send): bool
    {
        $invoice = $send->invoice();

        return !$invoice->isDelivered() && !$invoice->isTrashed();
    }

    private function deliver(InvoiceSend $send, ?FirmMailServer $server): void
    {
        $id = $send->id();
        try {
            if (!$server instanceof FirmMailServer) {
                throw new MailRefused(true, self::NO_SERVER, true, MailRefused::NO_SERVER);
            }
            $mail = $this->message($send, $server, LinkAudience::Customer, $send->to(), $send->cc());
            $this->gateway->send($mail, $server);
        } catch (MailRefused $refused) {
            $this->unit(function () use ($id, $refused): void {
                $send = $this->sends->lock($id);
                $send->recordAttempt($this->now(), $refused->safeReason, $refused->settingsProblem, $refused->reasonCode, $refused->smtpCode);
                if ($refused->permanent) {
                    $send->fail($this->now(), $refused->safeReason, $refused->settingsProblem, $refused->reasonCode, $refused->smtpCode);
                }
            });

            throw $refused->permanent ? new UnrecoverableMessageHandlingException($refused->safeReason, 0, $refused) : $refused;
        } catch (\Throwable $failure) {
            // Not the server's answer: release the claim, so the retry may try.
            $this->unit(function () use ($id): void {
                $this->sends->lock($id)->recordAttempt($this->now(), self::UNEXPECTED, code: MailRefused::NOT_HANDED_OVER);
            });

            throw $failure;
        }

        // The acceptance first, on the send alone: from here on no retry mails the customer.
        $this->unit(function () use ($id, $mail): void {
            $this->sends->lock($id)->markSent($this->now(), $mail->messageId);
        });
    }

    /**
     * Marks the invoice delivered by a send the server accepted, unless it is
     * delivered already or went to the trash meanwhile.
     *
     * @return InvoiceSend the send as stored now
     */
    private function recordDelivered(string $id, string $invoiceId): InvoiceSend
    {
        return $this->unit(function () use ($id, $invoiceId): InvoiceSend {
            // The invoice first, in the order trash takes them.
            $invoice = $this->invoices->lock($invoiceId);
            $send = $this->sends->lock($id);
            if ($invoice->isTrashed()) {
                if (!$invoice->isDelivered()) {
                    $this->logger->warning('The send {send} went out while its invoice {invoice} went to the trash; the invoice stays there, not delivered.', ['send' => $id, 'invoice' => $invoiceId]);
                }
            } else {
                $invoice->markDelivered($send->sentAt() ?? $this->now());
            }
            if (!$send->isCopyOwed()) {
                $send->release();
            }

            return $send;
        });
    }

    private function deliverCopy(InvoiceSend $send, ?FirmMailServer $server): void
    {
        $id = $send->id();
        $copyTo = $send->copyTo() ?? throw new \LogicException('Only a send with a copy owes one.');
        // Unknown until the server answers, so no later worker sends it a second time.
        $this->unit(function () use ($id): void {
            $this->sends->lock($id)->startCopy(self::COPY_UNKNOWN, MailRefused::COPY_UNKNOWN);
        });
        try {
            if (!$server instanceof FirmMailServer) {
                throw new MailRefused(true, self::NO_SERVER, true, MailRefused::NO_SERVER);
            }
            $this->gateway->send($this->message($send, $server, LinkAudience::Copy, [$copyTo], []), $server);
        } catch (MailRefused $refused) {
            $this->unit(function () use ($id, $refused): void {
                $this->sends->lock($id)->recordCopyFailure($refused->safeReason, $refused->reasonCode, $refused->smtpCode);
            });

            return;
        }
        $this->unit(function () use ($id): void {
            $this->sends->lock($id)->markCopySent($this->now());
        });
    }

    /**
     * @param list<string> $to
     * @param list<string> $cc
     *
     * @throws MailRefused when the stored send cannot make a message, or its link no
     *                     longer matches the application secret, which no retry changes
     */
    private function message(InvoiceSend $send, FirmMailServer $server, LinkAudience $audience, array $to, array $cc): MailMessage
    {
        $from = $server->fromAddress();
        $domain = substr($from, (int) strrpos($from, '@') + 1);
        $token = $this->tokens->token($send->id(), $audience);
        // Derived again from the application secret: if that changed since the send
        // was queued, the link would lead nowhere while the invoice counts as delivered.
        $stored = LinkAudience::Copy === $audience ? $send->copyTokenHash() : $send->tokenHash();
        if (null === $stored || !hash_equals($stored, $this->tokens->hash($token))) {
            throw new MailRefused(true, self::LINK_CHANGED, reasonCode: MailRefused::LINK_CHANGED);
        }
        $body = new LinkedMailBody($send->body(), $this->links->url($token));
        $replyTo = trim($this->settings->for($send->invoice()->firm())->contactEmail());

        try {
            return new MailMessage(
                $from,
                $server->fromName(),
                PartyEmail::isValid($replyTo) ? $replyTo : null,
                $to,
                $cc,
                $send->subject(),
                $body->text(),
                $body->html(),
                \sprintf('<%s%s@%s>', $send->id(), LinkAudience::Copy === $audience ? '.copy' : '', $domain),
            );
        } catch (\InvalidArgumentException) {
            throw new MailRefused(true, self::UNBUILDABLE, reasonCode: MailRefused::UNBUILDABLE);
        }
    }

    /**
     * @template T
     *
     * @param callable(): T $unit
     *
     * @return T
     */
    private function unit(callable $unit): mixed
    {
        return $this->retry->run(fn (): mixed => $this->work->run($unit));
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
