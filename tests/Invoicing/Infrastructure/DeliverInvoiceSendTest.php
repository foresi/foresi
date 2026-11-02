<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Infrastructure;

use App\Invoicing\Application\DeliverInvoiceSend;
use App\Invoicing\Application\MailMessage;
use App\Invoicing\Domain\LinkAudience;
use App\Invoicing\Infrastructure\Mail\SymfonyMailGateway;
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
        $send = $this->sent($invoice, ['body' => 'Dear customer, here is invoice {number}.']);

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

        $this->worker->run();
        self::assertSame([], $this->gateway->attempts(), 'another worker has it');

        // A claim older than the lease belongs to a worker that died.
        self::mockTime('2026-11-03 10:04:00');
        $this->worker->redeliver($this->worker->last());
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

    private function onlyMessage(): MailMessage
    {
        $messages = $this->gateway->messages();
        self::assertCount(1, $messages);

        return $messages[0];
    }

    private function deliveryFailure(): \Throwable
    {
        try {
            $this->worker->run();
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
