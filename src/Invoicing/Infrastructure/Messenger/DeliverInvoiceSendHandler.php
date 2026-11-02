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
use App\Invoicing\Application\InvoiceSends;
use App\Invoicing\Application\InvoiceStore;
use App\Invoicing\Application\LinkedMailBody;
use App\Invoicing\Application\LinkTokens;
use App\Invoicing\Application\MailGateway;
use App\Invoicing\Application\MailMessage;
use App\Invoicing\Application\MailRefused;
use App\Invoicing\Application\MailServers;
use App\Invoicing\Application\UnknownInvoiceSend;
use App\Invoicing\Domain\FirmMailServer;
use App\Invoicing\Domain\InvoiceSend;
use App\Invoicing\Domain\LinkAudience;
use App\Invoicing\Domain\SendStatus;
use App\Party\Application\TransactionalWork;
use App\Party\Domain\PartyEmail;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Delivers what the request froze, in the tenant of the message. A short unit
 * claims the send; the mail server is called outside any transaction, so a slow
 * server holds no lock; a second unit records the outcome.
 *
 * A send that is not queued sends nothing, so a redelivered message never mails
 * the customer twice. Only a worker that dies after the server accepted the mail
 * and before the second unit can repeat it once, with the same Message-ID. While
 * a worker holds the claim, a second message for the send leaves it alone; a
 * claim older than the lease belongs to a worker that died.
 *
 * A temporary refusal is counted and thrown again, so the queue retries it; a
 * permanent one fails the send at once and skips the retries. MarkSendFailed
 * fails the send when the retries run out. The copy to the firm goes after the
 * customer's mail, once: its failure is recorded and leaves the send as sent.
 */
#[AsMessageHandler]
final readonly class DeliverInvoiceSendHandler
{
    private const string LEASE = '-5 minutes';
    private const string NO_SERVER = 'The firm has no mail server: add one in the e-mail settings.';
    private const string UNBUILDABLE = 'The message could not be built from the stored send.';
    private const string UNEXPECTED = 'The message could not be handed to the mail server.';

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
        if (null === $owed) {
            return;
        }
        [$send, $audience] = $owed;
        $server = $this->servers->for($send->invoice()->firm());
        if (LinkAudience::Customer === $audience) {
            $send = $this->deliver($send, $server);
            if (!$send->isCopyOwed()) {
                return;
            }
        }
        $this->deliverCopy($send, $server);
    }

    /**
     * @return array{InvoiceSend, LinkAudience}|null what is owed now, claimed; null when nothing is
     */
    private function claim(string $id): ?array
    {
        return $this->retry->run(fn (): ?array => $this->work->run(function () use ($id): ?array {
            $send = $this->sends->lock($id);
            $now = $this->now();
            if ($send->isClaimedSince($now->modify(self::LEASE))) {
                return null;
            }
            $audience = match (true) {
                SendStatus::Queued === $send->status() => LinkAudience::Customer,
                SendStatus::Sent === $send->status() && $send->isCopyOwed() => LinkAudience::Copy,
                default => null,
            };
            if (null === $audience) {
                return null;
            }
            $send->claim($now);

            return [$send, $audience];
        }));
    }

    /**
     * @return InvoiceSend the send as recorded sent
     */
    private function deliver(InvoiceSend $send, ?FirmMailServer $server): InvoiceSend
    {
        $id = $send->id();
        $invoiceId = $send->invoice()->id();
        try {
            if (!$server instanceof FirmMailServer) {
                throw new MailRefused(true, self::NO_SERVER, true);
            }
            $mail = $this->message($send, $server, LinkAudience::Customer, $send->to(), $send->cc());
            $this->gateway->send($mail, $server);
        } catch (MailRefused $refused) {
            $this->unit(function () use ($id, $refused): void {
                $send = $this->sends->lock($id);
                $send->recordAttempt($this->now(), $refused->safeReason, $refused->settingsProblem);
                if ($refused->permanent) {
                    $send->fail($this->now(), $refused->safeReason, $refused->settingsProblem);
                }
            });

            throw $refused->permanent ? new UnrecoverableMessageHandlingException($refused->safeReason, 0, $refused) : $refused;
        } catch (\Throwable $failure) {
            // Not the server's answer: release the claim, so the retry may try.
            $this->unit(function () use ($id): void {
                $this->sends->lock($id)->recordAttempt($this->now(), self::UNEXPECTED);
            });

            throw $failure;
        }

        return $this->unit(function () use ($id, $invoiceId, $mail): InvoiceSend {
            // The invoice first, in the order trash takes them.
            $invoice = $this->invoices->lock($invoiceId);
            $send = $this->sends->lock($id);
            $send->markSent($this->now(), $mail->messageId);
            if ($invoice->isTrashed()) {
                $this->logger->warning('The send {send} went out while its invoice {invoice} went to the trash; the invoice stays there, not delivered.', ['send' => $id, 'invoice' => $invoiceId]);

                return $send;
            }
            $invoice->markDelivered($this->now());

            return $send;
        });
    }

    private function deliverCopy(InvoiceSend $send, ?FirmMailServer $server): void
    {
        $id = $send->id();
        $copyTo = $send->copyTo() ?? throw new \LogicException('Only a send with a copy owes one.');
        try {
            if (!$server instanceof FirmMailServer) {
                throw new MailRefused(true, self::NO_SERVER, true);
            }
            $this->gateway->send($this->message($send, $server, LinkAudience::Copy, [$copyTo], []), $server);
        } catch (MailRefused $refused) {
            $this->unit(function () use ($id, $refused): void {
                $this->sends->lock($id)->recordCopyFailure($refused->safeReason);
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
     * @throws MailRefused when the stored send cannot make a message, which no retry changes
     */
    private function message(InvoiceSend $send, FirmMailServer $server, LinkAudience $audience, array $to, array $cc): MailMessage
    {
        $from = $server->fromAddress();
        $domain = substr($from, (int) strrpos($from, '@') + 1);
        $body = new LinkedMailBody($send->body(), $this->links->url($this->tokens->token($send->id(), $audience)));
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
            throw new MailRefused(true, self::UNBUILDABLE);
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
