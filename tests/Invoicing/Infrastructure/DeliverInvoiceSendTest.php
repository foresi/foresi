<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Infrastructure;

use App\Invoicing\Application\DeliverInvoiceSend;
use App\Invoicing\Application\MailMessage;
use App\Invoicing\Application\MailRefused;
use App\Invoicing\Application\RequeueStuckSends;
use App\Invoicing\Domain\LinkAudience;
use App\Invoicing\Infrastructure\Mail\SymfonyMailGateway;
use App\Invoicing\Infrastructure\Messenger\SendLeaseHeld;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Infrastructure\Messenger\TenantStamp;
use App\Tests\Invoicing\Application\SendingTestCase;
use App\Tests\Support\Mail\RecordingMailGateway;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;

final class DeliverInvoiceSendTest extends SendingTestCase
{
    public function testDeliveryMarksSentAndDeliveredInOneStep(): void
    {
        $invoice = $this->issue->issue($this->input());
        $send = $this->sent($invoice);
        self::mockTime('2026-11-03 10:01:00');

        $this->worker->run();
        $this->em->clear();

        $row = $this->row($send->id());
        self::assertSame('sent', $row['status']);
        self::assertSame('2026-11-03 10:01:00', self::moment($row, 'sent_at'));
        self::assertSame('<'.$send->id().'@example.com>', $row['message_id']);
        self::assertNull($row['claimed_at']);
        self::assertSame('2026-11-03 10:01:00', self::moment($this->invoiceRow($invoice), 'delivered_at'));
        self::assertCount(1, $this->gateway->messages());
    }

    public function testTheMailContainsTheLinkOnceAndNoOtherHeaders(): void
    {
        $this->contactEmail('office@example.com');
        $invoice = $this->issue->issue($this->input());
        $send = $this->sent($invoice, ['cc' => ['accounts@example.com']]);

        $this->worker->run();

        $message = $this->onlyMessage();
        $link = $this->linkOf($send->id());
        self::assertSame(1, substr_count($message->text, $link));
        self::assertStringNotContainsString('{link}', $message->text);
        self::assertSame(['billing@example.com'], $message->to);
        self::assertSame(['accounts@example.com'], $message->cc);
        self::assertSame('office@example.com', $message->replyTo);
        self::assertSame('invoices@example.com', $message->from);
        self::assertSame('Example Firm', $message->fromName);

        $raw = new SymfonyMailGateway(self::getContainer()->get(\App\Invoicing\Application\MailSecrets::class), self::getContainer()->get(\App\Invoicing\Application\MailHostPolicy::class))->email($message)->toString();
        [$head] = explode("\r\n\r\n", $raw, 2);
        $lines = preg_split('/\r\n(?![ \t])/', $head);
        self::assertIsArray($lines);
        $names = array_map(static fn (string $line): string => strtolower(explode(':', $line, 2)[0]), $lines);
        self::assertCount(1, array_keys($names, 'to', true));
        self::assertCount(1, array_keys($names, 'cc', true));
        self::assertNotContains('bcc', $names);
        self::assertEqualsCanonicalizing(['from', 'reply-to', 'to', 'cc', 'subject', 'message-id', 'mime-version', 'date', 'content-type'], array_values(array_filter($names, static fn (string $name): bool => '' !== $name)));
    }

    public function testABodyWithoutLinkGetsItAppended(): void
    {
        $invoice = $this->issue->issue($this->input());
        $send = $this->sent($invoice, ['body' => 'Dear customer, here is invoice 20260001.']);

        $this->worker->run();

        $message = $this->onlyMessage();
        $link = $this->linkOf($send->id());
        self::assertSame("Dear customer, here is invoice 20260001.\n\n".$link, $message->text);
        self::assertSame(1, substr_count($message->html, 'href="'.$link.'"'));
    }

    public function testMarkupInBodyIsEscapedInHtml(): void
    {
        $invoice = $this->issue->issue($this->input());
        $send = $this->sent($invoice, ['body' => "<script>alert(1)</script> & <b>bold</b>\n\n{link}"]);

        $this->worker->run();

        $message = $this->onlyMessage();
        self::assertStringNotContainsString('<script>', $message->html);
        self::assertStringNotContainsString('<b>', $message->html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; &amp; &lt;b&gt;bold&lt;/b&gt;', $message->html);
        self::assertStringContainsString('<a href="'.$this->linkOf($send->id()).'">', $message->html);
        self::assertStringContainsString('<script>alert(1)</script>', $message->text);
    }

    public function testATemporaryFailureRecordsTheAttemptAndRethrows(): void
    {
        $invoice = $this->issue->issue($this->input());
        $send = $this->sent($invoice);
        $this->gateway->failNext(1, false);
        self::mockTime('2026-11-03 10:02:00');

        $failure = $this->deliveryFailure();

        self::assertNotInstanceOf(UnrecoverableMessageHandlingException::class, $failure);
        $row = $this->row($send->id());
        self::assertSame('queued', $row['status']);
        self::assertSame(1, self::integer($row, 'attempts'));
        self::assertSame('2026-11-03 10:02:00', self::moment($row, 'last_attempt_at'));
        self::assertSame('SMTP 451: the mail server asks to try again later.', $row['last_error']);
        self::assertNull($row['claimed_at']);
        self::assertNull($this->invoiceRow($invoice)['delivered_at']);
    }

    public function testASettingsProblemIsRecordedAsOne(): void
    {
        $invoice = $this->issue->issue($this->input());
        $send = $this->sent($invoice);
        $this->gateway->failNext(1, true, true);

        $failure = $this->deliveryFailure();

        self::assertInstanceOf(UnrecoverableMessageHandlingException::class, $failure);
        $row = $this->row($send->id());
        self::assertSame('failed', $row['status']);
        self::assertSame('SMTP 535: the mail server refused the login.', $row['last_error']);
        self::assertTrue(self::flag($row, 'settings_problem'));
    }

    public function testAPermanentFailureFailsWithoutRetry(): void
    {
        $invoice = $this->issue->issue($this->input());
        $send = $this->sent($invoice);
        $this->gateway->failNext(1, true);

        $failure = $this->deliveryFailure();

        self::assertInstanceOf(UnrecoverableMessageHandlingException::class, $failure);
        $row = $this->row($send->id());
        self::assertSame('failed', $row['status']);
        self::assertSame(1, self::integer($row, 'attempts'));
        self::assertSame('SMTP 550: the mail server refused the message.', $row['last_error']);
        self::assertFalse(self::flag($row, 'settings_problem'));
        self::assertNotNull($row['failed_at']);
        self::assertNull($this->invoiceRow($invoice)['delivered_at']);
    }

    public function testRetriesRunOutIntoVisibleFailure(): void
    {
        $invoice = $this->issue->issue($this->input());
        $send = $this->sent($invoice);
        $this->gateway->failNext(1, false);
        $failure = $this->deliveryFailure();
        self::assertSame('queued', $this->row($send->id())['status']);

        // The worker's own listener puts the first failure back on the queue.
        $this->events()->dispatch(new WorkerMessageFailedEvent($this->worker->last(), 'mail', $failure));
        self::assertSame('queued', $this->row($send->id())['status'], 'a retry is still to come');

        // After the fifth retry the queue gives up.
        self::mockTime('2026-11-03 15:00:00');
        $this->events()->dispatch(new WorkerMessageFailedEvent($this->worker->last()->with(new RedeliveryStamp(5)), 'mail', $failure));

        $row = $this->row($send->id());
        self::assertSame('failed', $row['status']);
        self::assertSame('2026-11-03 15:00:00', self::moment($row, 'failed_at'));
        self::assertSame('SMTP 451: the mail server asks to try again later.', $row['last_error']);
        self::assertNull($this->invoiceRow($invoice)['delivered_at']);
        self::assertSame([], $this->gateway->messages());
    }

    public function testRedeliveryOfASentRowSendsNothing(): void
    {
        $invoice = $this->issue->issue($this->input());
        $this->sent($invoice);
        $this->worker->run();

        $this->worker->redeliver($this->worker->last());

        self::assertCount(1, $this->gateway->messages());
    }

    public function testADeliveryUnderWayIsNotStartedTwice(): void
    {
        $invoice = $this->issue->issue($this->input());
        $send = $this->sent($invoice);
        $this->connection->executeStatement('UPDATE invoice_send SET claimed_at = ? WHERE id = ?', ['2026-11-03 09:58:00', $send->id()]);

        $this->worker->run(1);
        self::assertSame([], $this->gateway->attempts(), 'another worker has it');
        $waiting = $this->onlyWaiting(1);
        self::assertSame(185_000, $waiting->last(DelayStamp::class)?->getDelay(), 'back when the lease of 09:58 ends, and a little after');
        self::assertNull($waiting->last(RedeliveryStamp::class), 'waiting uses no retry');
        self::assertSame($this->tenant->toString(), $waiting->last(TenantStamp::class)?->tenantId);
        self::assertSame('queued', $this->row($send->id())['status']);

        // A retry that has to wait keeps the count it had.
        $this->mailQueue->reset();
        $this->worker->redeliver($this->worker->last()->with(new RedeliveryStamp(2)));
        $waiting = $this->onlyWaiting();
        self::assertSame([2], array_map(static fn (RedeliveryStamp $stamp): int => $stamp->getRetryCount(), $waiting->all(RedeliveryStamp::class)));
        self::assertNull($waiting->last(ReceivedStamp::class));

        // When it is due, the claim is older than the lease: its worker died.
        self::mockTime('2026-11-03 10:04:00');
        self::assertSame(1, $this->worker->run());
        self::assertCount(1, $this->gateway->messages());
        self::assertSame('sent', $this->row($send->id())['status']);
    }

    public function testACancelledRowSendsNothing(): void
    {
        $invoice = $this->issue->issue($this->input());
        $send = $this->sent($invoice);
        $this->trash->trash($invoice->id(), 'Wrong customer');

        $this->worker->run();

        self::assertSame([], $this->gateway->attempts());
        self::assertSame('cancelled', $this->row($send->id())['status']);
    }

    public function testTheRetryKeepsTheSameMessageId(): void
    {
        $invoice = $this->issue->issue($this->input());
        $send = $this->sent($invoice);
        $this->gateway->failNext(1, false);
        $this->deliveryFailure();

        $this->worker->redeliver($this->worker->last());

        $attempts = $this->gateway->attempts();
        self::assertCount(2, $attempts);
        self::assertSame('<'.$send->id().'@example.com>', $attempts[0]->messageId);
        self::assertSame($attempts[0]->messageId, $attempts[1]->messageId);
        self::assertSame($attempts[0]->text, $attempts[1]->text);
        self::assertSame('sent', $this->row($send->id())['status']);
        self::assertSame(1, self::integer($this->row($send->id()), 'attempts'));
    }

    public function testCopyGoesSeparatelyWithItsOwnTokenAndItsFailureKeepsTheSend(): void
    {
        $this->contactEmail('office@example.com');
        $invoice = $this->issue->issue($this->input());
        $send = $this->sent($invoice, ['copyToMe' => true, 'cc' => ['accounts@example.com']]);
        self::assertSame('office@example.com', $this->row($send->id())['copy_to']);

        $this->worker->run();

        $messages = $this->gateway->messages();
        self::assertCount(2, $messages);
        [$customer, $copy] = $messages;
        self::assertSame(['office@example.com'], $copy->to);
        self::assertSame([], $copy->cc);
        self::assertSame($customer->subject, $copy->subject);
        self::assertStringContainsString($this->linkOf($send->id(), LinkAudience::Copy), $copy->text);
        self::assertStringNotContainsString($this->linkOf($send->id()), $copy->text);
        self::assertSame('<'.$send->id().'.copy@example.com>', $copy->messageId);
        $row = $this->row($send->id());
        self::assertNotNull($row['copy_sent_at']);
        self::assertNull($row['copy_error']);

        // A redelivery owes nothing.
        $this->worker->redeliver($this->worker->last());
        self::assertCount(2, $this->gateway->messages());

        $this->contactEmail(RecordingMailGateway::REFUSING_RECIPIENT);
        $second = $this->sent($invoice, ['copyToMe' => true]);
        $this->worker->run();
        $row = $this->row($second->id());
        self::assertSame('sent', $row['status']);
        self::assertNull($row['copy_sent_at']);
        self::assertSame('SMTP 550: the mail server refused the message.', $row['copy_error']);
        self::assertNull($row['claimed_at']);
    }

    public function testASendWhoseLinkNoLongerMatchesFailsWithoutMailing(): void
    {
        $invoice = $this->issue->issue($this->input());
        $send = $this->sent($invoice);
        // Queued under another application secret: the link derived now would lead nowhere.
        $this->connection->executeStatement('UPDATE invoice_send SET token_hash = ? WHERE id = ?', [hash('sha256', 'an older secret'), $send->id()]);

        $failure = $this->deliveryFailure();

        self::assertInstanceOf(UnrecoverableMessageHandlingException::class, $failure);
        self::assertSame([], $this->gateway->attempts());
        $row = $this->row($send->id());
        self::assertSame('failed', $row['status']);
        self::assertSame(MailRefused::LINK_CHANGED, $row['last_error_code']);
        self::assertNull($this->invoiceRow($invoice)['delivered_at']);
    }

    public function testACopyWhoseLinkNoLongerMatchesIsNotMailed(): void
    {
        $this->contactEmail('office@example.com');
        $invoice = $this->issue->issue($this->input());
        $send = $this->sent($invoice, ['copyToMe' => true]);
        $this->connection->executeStatement('UPDATE invoice_send SET copy_token_hash = ? WHERE id = ?', [hash('sha256', 'an older secret'), $send->id()]);

        $this->worker->run();

        self::assertCount(1, $this->gateway->attempts(), 'the customer only');
        $row = $this->row($send->id());
        self::assertSame('sent', $row['status']);
        self::assertNull($row['copy_sent_at']);
        self::assertSame(MailRefused::LINK_CHANGED, $row['copy_error_code']);
        self::assertNull($row['claimed_at']);
    }

    public function testAMessageWithoutATenantIsRejected(): void
    {
        $invoice = $this->issue->issue($this->input());
        $send = $this->sent($invoice);

        try {
            self::getContainer()->get(MessageBusInterface::class)->dispatch(new Envelope(new DeliverInvoiceSend($send->id()), [new ReceivedStamp('mail')]));
            self::fail('A message without a tenant is refused.');
        } catch (UnrecoverableMessageHandlingException) {
        }
        self::assertSame([], $this->gateway->attempts());
    }

    public function testAMessageOfAnotherTenantFindsNothing(): void
    {
        $invoice = $this->issue->issue($this->input());
        $send = $this->sent($invoice);
        self::getContainer()->get(TenantContext::class)->reset();

        self::getContainer()->get(MessageBusInterface::class)->dispatch(new Envelope(new DeliverInvoiceSend($send->id()), [new ReceivedStamp('mail'), new TenantStamp(TenantId::generate()->toString())]));

        self::assertSame([], $this->gateway->attempts());
        self::getContainer()->get(TenantContext::class)->switchTo($this->tenant);
        self::assertSame('queued', $this->row($send->id())['status']);
    }

    public function testTrashDuringDeliveryLeavesTheInvoiceTrashed(): void
    {
        $invoice = $this->issue->issue($this->input());
        $send = $this->sent($invoice);
        RecordingMailGateway::whileSending(function () use ($invoice): void {
            $this->trash->trash($invoice->id(), 'Wrong customer');
        });

        $this->worker->run();
        $this->em->clear();

        $row = $this->row($send->id());
        self::assertSame('sent', $row['status'], 'the server took it: that is what happened');
        self::assertNotNull($row['revoked_at']);
        $stored = $this->invoiceRow($invoice);
        self::assertNotNull($stored['deleted_at']);
        self::assertNull($stored['delivered_at']);
        self::assertCount(1, $this->gateway->messages());
    }

    public function testAnAcceptedMailWhoseRecordFailedIsFinishedNotSentAgain(): void
    {
        $invoice = $this->issue->issue($this->input());
        $send = $this->sent($invoice);
        $this->gateway->failNext(1, false);
        $this->deliveryFailure();
        // While the server takes the mail, the invoice drops out of reach, so marking it delivered fails.
        $this->hideWhileSending('invoice', $invoice->id());

        self::mockTime('2026-11-03 10:05:00');
        $failure = $this->deliveryFailure(redeliver: true);
        $this->showAgain('invoice', $invoice->id());

        self::assertNotInstanceOf(SendLeaseHeld::class, $failure);
        $row = $this->row($send->id());
        self::assertSame(['sent', '2026-11-03 10:05:00'], [$row['status'], self::moment($row, 'claimed_at')], 'accepted, and still claimed for the rest');
        self::assertNull($this->invoiceRow($invoice)['delivered_at']);
        self::assertCount(1, $this->gateway->messages());

        // The queue's retry a minute later waits for the lease; the next one finishes the bookkeeping.
        self::mockTime('2026-11-03 10:06:00');
        $this->mailQueue->reset();
        $this->worker->redeliver($this->worker->last());
        self::assertSame(245_000, $this->onlyWaiting()->last(DelayStamp::class)?->getDelay());
        self::mockTime('2026-11-03 10:10:05');
        $this->worker->redeliver($this->worker->last());

        $row = $this->row($send->id());
        self::assertSame('sent', $row['status']);
        self::assertNull($row['claimed_at']);
        self::assertSame('2026-11-03 10:05:00', self::moment($this->invoiceRow($invoice), 'delivered_at'), 'delivered when the server took it');
        self::assertCount(1, $this->gateway->messages(), 'the customer got it once');
    }

    public function testRetriesRunningOutAfterAcceptanceNeitherFailNorStrandTheSend(): void
    {
        $invoice = $this->issue->issue($this->input());
        $send = $this->sent($invoice);
        $this->hideWhileSending('invoice', $invoice->id());
        $failure = $this->deliveryFailure();
        $this->showAgain('invoice', $invoice->id());

        // The queue gives up.
        self::mockTime('2026-11-03 15:00:00');
        $this->events()->dispatch(new WorkerMessageFailedEvent($this->worker->last()->with(new RedeliveryStamp(5)), 'mail', $failure));
        self::assertSame('sent', $this->row($send->id())['status'], 'an accepted mail never fails');

        // The job hands it back once; delivery finishes the bookkeeping without mailing.
        $this->mailQueue->reset();
        self::getContainer()->get(RequeueStuckSends::class)->run(null);
        self::getContainer()->get(RequeueStuckSends::class)->run(null);
        self::assertCount(1, [...$this->mailQueue->getSent()], 'not twice in a row');
        $this->worker->run();

        self::assertNull($this->row($send->id())['claimed_at']);
        self::assertNotNull($this->invoiceRow($invoice)['delivered_at']);
        self::assertCount(1, $this->gateway->messages());
    }

    public function testACopyWhoseRecordFailedIsNeverSentTwice(): void
    {
        $this->contactEmail('office@example.com');
        $invoice = $this->issue->issue($this->input());
        $send = $this->sent($invoice, ['copyToMe' => true]);
        // The customer's mail goes through; while the copy goes, the send drops out of reach.
        RecordingMailGateway::whileSending(function () use ($send): void {
            RecordingMailGateway::whileSending(function () use ($send): void {
                $this->hideWhileSending('invoice_send', $send->id());
            });
        });

        $this->deliveryFailure();
        $this->showAgain('invoice_send', $send->id());
        self::mockTime('2026-11-03 10:06:00');
        $this->worker->redeliver($this->worker->last());

        self::assertCount(2, $this->gateway->attempts(), 'the customer once, the copy once');
        $row = $this->row($send->id());
        self::assertSame('sent', $row['status']);
        self::assertNull($row['copy_sent_at']);
        self::assertSame('The copy was handed to the mail server, but its answer was not recorded.', $row['copy_error']);
        self::assertNull($row['claimed_at']);
        self::assertNotNull($this->invoiceRow($invoice)['delivered_at']);
    }

    public function testAWorkerThatDiedHoldingTheClaimIsHandedBack(): void
    {
        $invoice = $this->issue->issue($this->input());
        $send = $this->sent($invoice);
        $this->gateway->failNext(1, false);
        $this->deliveryFailure();
        // A later try claimed it at 10:20 and its worker died; its retries are gone with it.
        $this->connection->executeStatement('UPDATE invoice_send SET claimed_at = ? WHERE id = ?', ['2026-11-03 10:20:00', $send->id()]);
        $this->mailQueue->reset();
        $job = self::getContainer()->get(RequeueStuckSends::class);

        self::mockTime('2026-11-03 10:30:00');
        $job->run(null);
        self::assertSame([], [...$this->mailQueue->getSent()], 'a worker may still come back within the lease');

        self::mockTime('2026-11-03 10:36:00');
        $job->run(null);
        self::assertCount(1, [...$this->mailQueue->getSent()]);
        self::mockTime('2026-11-03 10:41:00');
        $job->run(null);
        self::assertCount(1, [...$this->mailQueue->getSent()], 'queued again at most once in fifteen minutes');

        $this->worker->run();
        self::assertSame('sent', $this->row($send->id())['status']);
        self::assertCount(1, $this->gateway->messages());
    }

    public function testASendInItsRetriesIsNotHandedBack(): void
    {
        $invoice = $this->issue->issue($this->input());
        $this->sent($invoice);
        $this->gateway->failNext(1, false);
        $this->deliveryFailure();
        $this->mailQueue->reset();

        // Its retry waits up to four hours in the queue already.
        self::mockTime('2026-11-03 12:00:00');
        self::getContainer()->get(RequeueStuckSends::class)->run(null);

        self::assertSame([], [...$this->mailQueue->getSent()]);
    }

    /**
     * Moves a row to another tenant during the next send, so whatever is written
     * about it next fails.
     */
    private function hideWhileSending(string $table, string $id): void
    {
        $connection = $this->connection;
        $move = static function () use ($connection, $table, $id): void {
            $connection->executeStatement(\sprintf("UPDATE %s SET tenant_id = '00000000-0000-0000-0000-000000000000' WHERE id = ?", $table), [$id]);
        };
        if ('invoice_send' === $table) {
            $move();

            return;
        }
        RecordingMailGateway::whileSending($move);
    }

    private function showAgain(string $table, string $id): void
    {
        $this->connection->executeStatement(\sprintf('UPDATE %s SET tenant_id = ? WHERE id = ?', $table), [$this->tenant->toString(), $id]);
        $this->em->clear();
    }

    /**
     * The one message the queue got after the first $before, not due before its delay.
     */
    private function onlyWaiting(int $before = 0): Envelope
    {
        $waiting = \array_slice([...$this->mailQueue->getSent()], $before);
        self::assertCount(1, $waiting);
        self::assertSame([], [...$this->mailQueue->get()], 'not due yet');

        return $waiting[0];
    }

    private function onlyMessage(): MailMessage
    {
        $messages = $this->gateway->messages();
        self::assertCount(1, $messages);

        return $messages[0];
    }

    private function deliveryFailure(bool $redeliver = false): \Throwable
    {
        try {
            $redeliver ? $this->worker->redeliver($this->worker->last()) : $this->worker->run();
        } catch (HandlerFailedException $failed) {
            return $failed->getPrevious() ?? $failed;
        }
        self::fail('The delivery was expected to fail.');
    }

    private function events(): EventDispatcherInterface
    {
        return self::getContainer()->get(EventDispatcherInterface::class);
    }
}
