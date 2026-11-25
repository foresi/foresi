<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Banking\Integration\Country;

use Foresi\Banking\Application\BankFeedDirectory;
use Foresi\Banking\Application\BankFeedPurpose;
use Foresi\Banking\Application\BankFeeds;
use Foresi\Banking\Application\InboundMessages;
use Foresi\Banking\Application\InboundProcessing;
use Foresi\Banking\Application\ProcessInboundMessage;
use Foresi\Invoicing\Application\PaymentState;
use Foresi\Mail\Application\DailyLimit;
use Foresi\Mail\Application\InboundAddresses;
use Foresi\Mail\Application\InboundAddressStore;
use Foresi\Mail\Application\InboundFiles;
use Foresi\Mail\Application\InboundPurposes;
use Foresi\Mail\Application\MailMessages;
use Foresi\Mail\Application\MailReader;
use Foresi\Mail\Application\MailTransaction;
use Foresi\Mail\Application\NotifyOverLimit;
use Foresi\Mail\Application\ReceiveMail;
use Foresi\Mail\Application\ReceiveOutcome;
use Foresi\Mail\Application\SenderVerdict;
use Foresi\Mail\Domain\InboundChannel;
use Foresi\Tests\Banking\Support\InboundTestCase;
use Foresi\Tests\Banking\Support\Scalars;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * A synthetic Komerční banka notice from the webhook to the paid invoice: it is
 * stored, queued, taken off the queue by the bus as a worker would, parsed,
 * judged and paired. Nothing is a real message.
 */
final class KbEndToEndTest extends InboundTestCase
{
    public function testAVerifiedKbIncomingNoticePaysTheInvoice(): void
    {
        $invoice = $this->kbInvoice();
        $id = $this->received($this->notice('incoming-booked'));

        $this->work();

        $row = $this->messageRow($id);
        self::assertSame(['processed', 'verified', 'dmarc', 'cz.kb', 2, null, 1], [$row['status'], $row['trust'], $row['trust_basis'], $row['parser_id'], self::int($row['parser_version']), $row['failure'], self::int($row['attempts'])]);
        self::assertStringStartsWith('2026-11-20 12:00:00', Scalars::text($row['processed_at']));
        $movements = $this->movementsOf($id);
        self::assertCount(1, $movements);
        self::assertSame([$this->account->id(), 'incoming', 15000, 'CZK', '2026001', 'paired', null, 'verified'], [$movements[0]['firm_bank_account_id'], $movements[0]['direction'], self::int($movements[0]['amount_minor']), $movements[0]['currency'], $movements[0]['variable_symbol'], $movements[0]['state'], $movements[0]['unmatched_reason'], $movements[0]['trust']]);
        self::assertNotNull($movements[0]['payment_id']);
        self::assertSame(PaymentState::Paid, $this->stateOf($invoice));
    }

    public function testAnUnverifiedNoticeRecordsButDoesNotPay(): void
    {
        $invoice = $this->kbInvoice();
        // No receiving server vouches for the sender.
        $id = $this->received($this->notice('incoming-booked', auth: null));

        $this->work();

        $row = $this->messageRow($id);
        self::assertSame(['processed', 'unverified', 'no-results'], [$row['status'], $row['trust'], $row['trust_basis']]);
        $movements = $this->movementsOf($id);
        self::assertCount(1, $movements);
        self::assertSame(['unmatched', 'unverified', null], [$movements[0]['state'], $movements[0]['unmatched_reason'], $movements[0]['payment_id']]);
        self::assertSame(PaymentState::Unpaid, $this->stateOf($invoice));
        self::assertSame(0, $this->paymentCount());
    }

    public function testADkimPassAloneDoesNotPay(): void
    {
        // A pass a sender could have smuggled into the header proves too little: only DMARC verifies.
        $invoice = $this->kbInvoice();
        $id = $this->received($this->notice('incoming-booked', auth: 'aligned-dkim-pass'));

        $this->work();

        self::assertSame(['processed', 'unverified', 'unsigned'], array_values(array_intersect_key($this->messageRow($id), array_flip(['status', 'trust', 'trust_basis']))));
        self::assertSame('unverified', $this->movementsOf($id)[0]['unmatched_reason']);
        self::assertSame(PaymentState::Unpaid, $this->stateOf($invoice));
    }

    public function testAForgedNoticeIsRejectedWithNoMovement(): void
    {
        $invoice = $this->kbInvoice();
        $id = $this->received($this->notice('incoming-booked', auth: 'aligned-dkim-fail'));

        $this->work();

        $row = $this->messageRow($id);
        self::assertSame(['rejected', 'failed', 'dkim-fail', 'untrusted', 'dkim-fail', 'cz.kb'], [$row['status'], $row['trust'], $row['trust_basis'], $row['failure'], $row['failure_detail'], $row['parser_id']]);
        self::assertSame([], $this->movementsOf($id));
        self::assertSame(PaymentState::Unpaid, $this->stateOf($invoice));
    }

    public function testADmarcFailIsRejected(): void
    {
        $id = $this->received($this->notice('incoming-booked', auth: 'dmarc-fail'));

        $this->work();

        self::assertSame(['rejected', 'dmarc-fail'], [$this->messageRow($id)['status'], $this->messageRow($id)['trust_basis']]);
        self::assertSame([], $this->movementsOf($id));
    }

    public function testAnOutgoingNoticeNoRefundFitsIsRecordedOnly(): void
    {
        $id = $this->received($this->notice('outgoing-booked'));

        $this->work();

        self::assertSame('processed', $this->messageRow($id)['status']);
        $movements = $this->movementsOf($id);
        self::assertCount(1, $movements);
        self::assertSame(['outgoing', 'recorded', null, null], [$movements[0]['direction'], $movements[0]['state'], $movements[0]['unmatched_reason'], $movements[0]['payment_id']]);
        self::assertSame(0, $this->paymentCount());
    }

    public function testTwoPaymentsInOneNoticePayTwoInvoices(): void
    {
        $first = $this->kbInvoice();
        $second = $this->kbInvoice('2026002', '2400.00');
        $id = $this->received($this->notice('two-payments'));

        $this->work();

        self::assertSame('processed', $this->messageRow($id)['status']);
        self::assertSame(['paired', 'paired'], array_column($this->movementsOf($id), 'state'));
        self::assertSame(PaymentState::Paid, $this->stateOf($first));
        self::assertSame(PaymentState::Paid, $this->stateOf($second));
        self::assertSame(2, $this->paymentCount());
    }

    public function testARedeliveredQueueMessageMakesOneMovementAndOnePayment(): void
    {
        // The worker died after committing and before acknowledging: the queue delivers again.
        $invoice = $this->kbInvoice();
        $id = $this->received($this->notice('incoming-booked'));
        $envelopes = $this->queued();

        $this->handle($envelopes);
        $this->handle($envelopes);

        self::assertCount(1, $this->movementsOf($id));
        self::assertSame(1, $this->paymentCount());
        self::assertSame(1, self::int($this->messageRow($id)['attempts']));
        self::assertSame(PaymentState::Paid, $this->stateOf($invoice));
    }

    public function testTheSameNoticeForwardedTwiceIsOneMovement(): void
    {
        $invoice = $this->kbInvoice();
        $raw = $this->notice('incoming-booked');
        $this->received($raw);
        // Fetched from the mailbox after the webhook stored it: one Message-ID, one row.
        self::assertSame(ReceiveOutcome::Duplicate, $this->receive->receive($raw, InboundChannel::Imap));

        $this->work();

        self::assertSame(1, self::int($this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM bank_movement')));
        self::assertSame(1, $this->paymentCount());
        self::assertSame(PaymentState::Paid, $this->stateOf($invoice));
    }

    public function testANoticeHandledWhileItIsStoredPaysTheInvoice(): void
    {
        // A synchronous queue, as in the browser tests, handles the message inside the transaction that stores it.
        $invoice = $this->kbInvoice();
        $container = self::getContainer();
        $bus = $container->get(MessageBusInterface::class);
        $inline = new readonly class($bus) implements InboundProcessing {
            public function __construct(private MessageBusInterface $bus)
            {
            }

            public function queue(string $messageId): void
            {
                // What the sync transport hands back to the bus: the message as received.
                $this->bus->dispatch(new Envelope(new ProcessInboundMessage($messageId), [new ReceivedStamp('sync')]));
            }
        };
        $purpose = new BankFeedPurpose(
            $container->get(BankFeedDirectory::class),
            $container->get(InboundMessages::class),
            $inline,
            $container->get(BankFeeds::class),
            $container->get(ClockInterface::class),
        );
        $receive = new ReceiveMail(
            $container->get(MailReader::class),
            $container->get(InboundAddresses::class),
            $container->get(InboundAddressStore::class),
            new InboundPurposes([$purpose]),
            $container->get(MailMessages::class),
            $container->get(InboundFiles::class),
            $container->get(SenderVerdict::class),
            $container->get(MailTransaction::class),
            $container->get(ClockInterface::class),
            $container->get(DailyLimit::class),
            $container->get(NotifyOverLimit::class),
        );

        self::assertSame(ReceiveOutcome::Stored, $receive->receive($this->notice('incoming-booked'), InboundChannel::Webhook));

        self::assertSame('processed', $this->em->getConnection()->fetchOne('SELECT status FROM inbound_message'));
        self::assertSame(PaymentState::Paid, $this->stateOf($invoice));
    }

    /**
     * Handles every queued message as a worker would.
     */
    private function work(): void
    {
        $this->handle($this->queued());
    }

    /**
     * @return list<Envelope>
     */
    private function queued(): array
    {
        $queue = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $queue);
        $envelopes = array_values([...$queue->getSent()]);
        self::assertNotEmpty($envelopes);
        $queue->reset();

        return $envelopes;
    }

    /**
     * @param list<Envelope> $envelopes
     */
    private function handle(array $envelopes): void
    {
        $bus = self::getContainer()->get(MessageBusInterface::class);
        foreach ($envelopes as $envelope) {
            $bus->dispatch($envelope->with(new ReceivedStamp('async')));
        }
    }
}
