<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Audit\Application\AuditActor;
use App\Banking\Application\Candidates;
use App\Invoicing\Application\AdvanceDocuments;
use App\Invoicing\Application\AllocationRequest;
use App\Invoicing\Application\CancelPayment;
use App\Invoicing\Application\ConflictRetry;
use App\Invoicing\Application\InvoiceHistory;
use App\Invoicing\Application\InvoiceHistoryEntry;
use App\Invoicing\Application\InvoicePayments;
use App\Invoicing\Application\InvoiceStatus;
use App\Invoicing\Application\InvoicingCountries;
use App\Invoicing\Application\IssuePaymentTaxDocument;
use App\Invoicing\Application\PaymentRefused;
use App\Invoicing\Application\PaymentRequest;
use App\Invoicing\Application\PaymentSourceRelease;
use App\Invoicing\Application\PaymentState;
use App\Invoicing\Application\PaymentTaxDocumentTexts;
use App\Invoicing\Application\RecordPayment;
use App\Invoicing\Application\UnknownPayment;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\Payment;
use App\Invoicing\Domain\PaymentSource;
use App\Invoicing\Domain\PriceType;
use App\Party\Application\TransactionalWork;
use App\Party\Domain\PaymentMethod;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tests\Banking\Support\LockLog;
use App\Tests\Banking\Support\LoggingInvoiceStore;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

final class RecordPaymentTest extends InvoiceTestCase
{
    private const string MOVEMENT = '01963c5e-0000-7000-8000-0000000000aa';

    private RecordPayment $record;
    private CancelPayment $cancel;
    private InvoicePayments $payments;

    protected function setUp(): void
    {
        parent::setUp();
        // In Prague it is 21 November already.
        Clock::set(new MockClock('2026-11-20 23:30:00', 'UTC'));
        $container = self::getContainer();
        $this->record = $container->get(RecordPayment::class);
        $this->cancel = $container->get(CancelPayment::class);
        $this->payments = $container->get(InvoicePayments::class);
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testAFullPaymentStoresPaymentAndAllocationAndShowsPaid(): void
    {
        $invoice = $this->issue->issue($this->input());

        $payment = $this->pay($invoice, '1210.00', note: 'Paid at the counter', method: PaymentMethod::Cash);

        $this->em->clear();
        $connection = $this->em->getConnection();
        $row = $connection->fetchAssociative('SELECT legal_entity_id, received_on, amount_minor, currency, method, source, bank_movement_id, note, cancelled_at FROM payment WHERE id = ?', [$payment->id()]);
        self::assertIsArray($row);
        self::assertSame($this->firm->id(), $row['legal_entity_id']);
        self::assertSame('2026-11-20', $row['received_on']);
        self::assertSame(121000, self::int($row['amount_minor']));
        self::assertSame(['CZK', 'cash', 'manual', null, 'Paid at the counter', null], [$row['currency'], $row['method'], $row['source'], $row['bank_movement_id'], $row['note'], $row['cancelled_at']]);
        $allocations = $connection->fetchAllAssociative('SELECT invoice_id, amount_minor, settlement_minor FROM payment_allocation WHERE payment_id = ?', [$payment->id()]);
        self::assertSame([[$invoice->id(), 121000, 0]], array_map(static fn (array $allocation): array => [$allocation['invoice_id'], self::int($allocation['amount_minor']), self::int($allocation['settlement_minor'])], $allocations));

        $stored = $this->reloaded($invoice);
        self::assertSame(121000, $this->payments->paidOf($stored)->minor());
        self::assertSame(0, $this->payments->remainingOf($stored)->minor());
        self::assertSame(InvoiceStatus::Paid, $this->statusOf($stored));
    }

    public function testAPartialPaymentIsPartlyPaidAndStillOverdueAfterDue(): void
    {
        $invoice = $this->issue->issue($this->input());

        $this->pay($invoice, '500.00');

        $stored = $this->reloaded($invoice);
        self::assertSame(71000, $this->payments->remainingOf($stored)->minor());
        self::assertSame(PaymentState::PartlyPaid, PaymentState::of($stored->payable(), $this->payments->paidOf($stored)));
        self::assertSame(InvoiceStatus::Overdue, $this->statusOf($stored));
        self::assertSame(InvoiceStatus::PartlyPaid, InvoiceStatus::ofInvoice($stored, new \DateTimeImmutable('2026-11-10'), $this->payments->paidOf($stored)));
        self::assertSame(InvoiceStatus::PartlyPaid, InvoiceStatus::ofInvoice($stored, new \DateTimeImmutable('2026-11-15 23:59'), $this->payments->paidOf($stored)), 'On its due date it is not overdue yet.');
        self::assertSame(InvoiceStatus::Overdue, InvoiceStatus::ofInvoice($stored, new \DateTimeImmutable('2026-11-16'), $this->payments->paidOf($stored)));
    }

    public function testAnOverpaymentIsOverpaid(): void
    {
        $invoice = $this->issue->issue($this->input());

        $this->pay($invoice, '1300.00');

        $stored = $this->reloaded($invoice);
        self::assertSame(-9000, $this->payments->remainingOf($stored)->minor());
        self::assertSame(InvoiceStatus::Overpaid, $this->statusOf($stored));
    }

    public function testOnePaymentOverTwoInvoicesNeedsTheSumToMatch(): void
    {
        $first = $this->issue->issue($this->input());
        $second = $this->issue->issue($this->input());

        $this->assertRefused(PaymentRefused::AMOUNT_MISMATCH, fn (): Payment => $this->record->record($this->request('100.00', [
            new AllocationRequest($first->id(), $this->czk('60.00')),
            new AllocationRequest($second->id(), $this->czk('30.00')),
        ])));
        $this->assertNothingStored();

        $payment = $this->record->record($this->request('100.00', [
            new AllocationRequest($second->id(), $this->czk('40.00')),
            new AllocationRequest($first->id(), $this->czk('60.00')),
        ]));

        $this->em->clear();
        self::assertSame(10000, $this->payments->get($payment->id())->amount()->minor());
        self::assertSame(6000, $this->payments->paidOf($this->store->get($first->id()))->minor());
        self::assertSame(4000, $this->payments->paidOf($this->store->get($second->id()))->minor());
        self::assertSame([$first->id() => 6000, $second->id() => 4000], $this->payments->paidFor([$first->id(), $second->id()]));
    }

    public function testEachInvoiceIsLockedOnceInAscendingOrderOfItsId(): void
    {
        $first = $this->issue->issue($this->input());
        $second = $this->issue->issue($this->input());
        $ids = [$first->id(), $second->id()];
        sort($ids, \SORT_STRING);
        $log = new LockLog(self::getContainer()->get(Candidates::class));

        $payment = $this->recordingWith($log)->record($this->request('100.00', [
            new AllocationRequest($ids[1], $this->czk('30.00')),
            new AllocationRequest($ids[0], $this->czk('60.00')),
            new AllocationRequest($ids[1], $this->czk('10.00')),
        ]));

        self::assertSame(['lock:'.$ids[0], 'lock:'.$ids[1]], $log->entries);
        $this->em->clear();
        self::assertSame([$ids[0] => 6000, $ids[1] => 4000], $this->payments->paidFor($ids));
        self::assertSame(10000, $this->payments->get($payment->id())->amount()->minor());
    }

    public function testCancellingLocksEveryInvoiceThePaymentPaid(): void
    {
        $first = $this->issue->issue($this->input());
        $second = $this->issue->issue($this->input());
        $payment = $this->record->record($this->request('100.00', [
            new AllocationRequest($first->id(), $this->czk('60.00')),
            new AllocationRequest($second->id(), $this->czk('40.00')),
        ]));
        $log = new LockLog(self::getContainer()->get(Candidates::class));
        $container = self::getContainer();
        $cancel = new CancelPayment($this->payments, new LoggingInvoiceStore($this->store, $log), $container->get(TransactionalWork::class), $container->get(ConflictRetry::class), $container->get(AuditActor::class), Clock::get(), $container->get(PaymentSourceRelease::class), ...$this->taxDocumentParts());

        $cancel->cancel($payment->id());

        $expected = ['lock:'.$first->id(), 'lock:'.$second->id()];
        $actual = $log->entries;
        sort($expected);
        sort($actual);
        self::assertSame($expected, $actual);
        self::assertTrue($this->payments->get($payment->id())->isCancelled());
    }

    public function testAValidSettlementIsStillRefusedOverTwoInvoices(): void
    {
        $invoice = $this->issue->issue($this->input());
        $other = $this->issue->issue($this->input());

        // 1209.50 and 0.50 written off close the first invoice exactly; a second invoice still forbids it.
        $this->assertRefused(PaymentRefused::SETTLEMENT_NOT_ALLOWED, fn (): Payment => $this->record->record($this->request('1309.50', [
            new AllocationRequest($invoice->id(), $this->czk('1209.50'), $this->czk('0.50')),
            new AllocationRequest($other->id(), $this->czk('100.00')),
        ])));
        $this->assertNothingStored();
    }

    public function testAZeroSettlementIsNoSettlementAndNeedNotCloseTheInvoice(): void
    {
        $invoice = $this->issue->issue($this->input());

        $this->record->record($this->request('500.00', [new AllocationRequest($invoice->id(), $this->czk('500.00'), $this->czk('0.00'))]));

        $stored = $this->reloaded($invoice);
        self::assertSame(50000, $this->payments->paidOf($stored)->minor());
        self::assertSame(PaymentState::PartlyPaid, PaymentState::of($stored->payable(), $this->payments->paidOf($stored)));
    }

    public function testASettlementCountsTowardTheInvoiceButNotThePayment(): void
    {
        $invoice = $this->issue->issue($this->input([$this->line(price: '1000.00', type: PriceType::WithVat)]));
        self::assertSame(100000, $invoice->payable()->minor());

        $payment = $this->record->record($this->request('999.50', [new AllocationRequest($invoice->id(), $this->czk('999.50'), $this->czk('0.50'))]));

        $this->em->clear();
        self::assertSame(99950, $this->payments->get($payment->id())->amount()->minor());
        $stored = $this->store->get($invoice->id());
        self::assertSame(100000, $this->payments->paidOf($stored)->minor());
        self::assertSame(InvoiceStatus::Paid, $this->statusOf($stored));
        $lines = $this->payments->linesOf($stored);
        self::assertCount(1, $lines);
        self::assertSame([99950, 50], [$lines[0]->allocated->minor(), $lines[0]->settlement->minor()]);
    }

    public function testANegativeSettlementTakesBackAnOverpaidHaler(): void
    {
        $invoice = $this->issue->issue($this->input([$this->line(price: '1000.00', type: PriceType::WithVat)]));

        $this->record->record($this->request('1000.40', [new AllocationRequest($invoice->id(), $this->czk('1000.40'), $this->czk('-0.40'))]));

        $stored = $this->reloaded($invoice);
        self::assertSame(100000, $this->payments->paidOf($stored)->minor());
        self::assertSame(InvoiceStatus::Paid, $this->statusOf($stored));
    }

    public function testASettlementIsRefusedAbove99MinorOrOverTwoInvoices(): void
    {
        $invoice = $this->issue->issue($this->input());
        $other = $this->issue->issue($this->input());

        $this->assertRefused(PaymentRefused::SETTLEMENT_NOT_ALLOWED, fn (): Payment => $this->record->record($this->request('1209.00', [new AllocationRequest($invoice->id(), $this->czk('1209.00'), $this->czk('1.00'))])));
        $this->assertRefused(PaymentRefused::SETTLEMENT_NOT_ALLOWED, fn (): Payment => $this->record->record($this->request('1211.00', [new AllocationRequest($invoice->id(), $this->czk('1211.00'), $this->czk('-1.00'))])));
        $this->assertRefused(PaymentRefused::SETTLEMENT_NOT_ALLOWED, fn (): Payment => $this->record->record($this->request('1209.50', [
            new AllocationRequest($invoice->id(), $this->czk('1000.00'), $this->czk('0.50')),
            new AllocationRequest($other->id(), $this->czk('209.50')),
        ])));
        $this->assertRefused(PaymentRefused::CURRENCY_MISMATCH, fn (): Payment => $this->record->record($this->request('1209.50', [new AllocationRequest($invoice->id(), $this->czk('1209.50'), Money::of('0.50', Currency::EUR))])));
        $this->assertNothingStored();

        // 99 minor units is the most a difference may be.
        $this->record->record($this->request('1209.01', [new AllocationRequest($invoice->id(), $this->czk('1209.01'), $this->czk('0.99'))]));
        self::assertSame(InvoiceStatus::Paid, $this->statusOf($this->reloaded($invoice)));
    }

    public function testASettlementThatDoesNotCloseTheInvoiceIsRefused(): void
    {
        $invoice = $this->issue->issue($this->input());

        // 1000.00 and 0.50 written off still leave 209.50 to pay.
        $this->assertRefused(PaymentRefused::SETTLEMENT_NOT_ALLOWED, fn (): Payment => $this->record->record($this->request('1000.00', [new AllocationRequest($invoice->id(), $this->czk('1000.00'), $this->czk('0.50'))])));
        $this->assertNothingStored();

        // What was paid before counts: after 500.00, 709.50 and 0.50 close the invoice.
        $this->pay($invoice, '500.00');
        $this->assertRefused(PaymentRefused::SETTLEMENT_NOT_ALLOWED, fn (): Payment => $this->record->record($this->request('1209.50', [new AllocationRequest($invoice->id(), $this->czk('1209.50'), $this->czk('0.50'))])));
        $this->record->record($this->request('709.50', [new AllocationRequest($invoice->id(), $this->czk('709.50'), $this->czk('0.50'))]));
        $stored = $this->reloaded($invoice);
        self::assertSame(0, $this->payments->remainingOf($stored)->minor());
        self::assertSame(InvoiceStatus::Paid, $this->statusOf($stored));
    }

    public function testAnOverpayingSettlementIsRefused(): void
    {
        $invoice = $this->issue->issue($this->input());

        // The payment already covers the invoice: writing off more would overpay it.
        $this->assertRefused(PaymentRefused::SETTLEMENT_NOT_ALLOWED, fn (): Payment => $this->record->record($this->request('1210.50', [new AllocationRequest($invoice->id(), $this->czk('1210.50'), $this->czk('0.50'))])));
        // A negative settlement may take back only what is overpaid.
        $this->assertRefused(PaymentRefused::SETTLEMENT_NOT_ALLOWED, fn (): Payment => $this->record->record($this->request('1210.40', [new AllocationRequest($invoice->id(), $this->czk('1210.40'), $this->czk('-0.50'))])));
        $this->assertNothingStored();
    }

    public function testANonCzechFirmCannotSettleInCzk(): void
    {
        $invoice = $this->issue->issue($this->input());
        // The writing off of coins is a Czech custom: it follows the firm's country, not the currency.
        $this->em->getConnection()->executeStatement("UPDATE legal_entity SET address_country_code = 'SK' WHERE id = ?", [$this->firm->id()]);
        $this->em->clear();

        $this->assertRefused(PaymentRefused::SETTLEMENT_NOT_ALLOWED, fn (): Payment => $this->record->record($this->request('1209.50', [new AllocationRequest($invoice->id(), $this->czk('1209.50'), $this->czk('0.50'))])));
        $this->assertNothingStored();

        $this->pay($invoice, '1210.00');
        self::assertSame(InvoiceStatus::Paid, $this->statusOf($this->reloaded($invoice)));
    }

    public function testAZeroOrNegativeAmountIsRefused(): void
    {
        $invoice = $this->issue->issue($this->input());

        $this->assertRefused(PaymentRefused::NOT_POSITIVE, fn (): Payment => $this->record->record($this->request('0.00', [new AllocationRequest($invoice->id(), $this->czk('0.00'))])));
        $this->assertRefused(PaymentRefused::NOT_POSITIVE, fn (): Payment => $this->record->record($this->request('-10.00', [new AllocationRequest($invoice->id(), $this->czk('-10.00'))])));
        // Every allocation takes a part of the payment, never gives some back.
        $this->assertRefused(PaymentRefused::NOT_POSITIVE, fn (): Payment => $this->record->record($this->request('100.00', [
            new AllocationRequest($invoice->id(), $this->czk('110.00')),
            new AllocationRequest($invoice->id(), $this->czk('-10.00')),
        ])));
        $this->assertRefused(PaymentRefused::NO_ALLOCATION, fn (): Payment => $this->record->record($this->request('100.00', [])));
        $this->assertNothingStored();
    }

    public function testATooLongNoteIsRefused(): void
    {
        $invoice = $this->issue->issue($this->input());

        $this->assertRefused(PaymentRefused::NOTE_TOO_LONG, fn (): Payment => $this->record->record($this->request('100.00', [new AllocationRequest($invoice->id(), $this->czk('100.00'))], note: str_repeat('ř', Payment::MAX_NOTE + 1))));
        $this->assertNothingStored();

        $payment = $this->record->record($this->request('100.00', [new AllocationRequest($invoice->id(), $this->czk('100.00'))], note: str_repeat('ř', Payment::MAX_NOTE)));
        $this->em->clear();
        self::assertSame(Payment::MAX_NOTE, mb_strlen($this->payments->get($payment->id())->note()));
    }

    public function testAnotherFirmsInvoiceIsRefused(): void
    {
        $other = $this->firm(vatId: 'CZ12345687', registrationNumber: '12345687');
        $series = $this->seriesOf($other);
        $this->em->flush();
        $theirs = $this->issue->issue($this->input(overrides: ['firmId' => $other->id(), 'seriesId' => $series->id()]));

        $this->assertRefused(PaymentRefused::WRONG_FIRM, fn (): Payment => $this->pay($theirs, '100.00'));
        $this->assertNothingStored();
    }

    public function testAnotherCurrencyIsRefused(): void
    {
        $invoice = $this->issue->issue($this->input());

        $this->assertRefused(PaymentRefused::CURRENCY_MISMATCH, fn (): Payment => $this->record->record(new PaymentRequest(
            $this->firm->id(),
            new \DateTimeImmutable('2026-11-20'),
            Money::of('100.00', Currency::EUR),
            PaymentMethod::BankTransfer,
            PaymentSource::Manual,
            null,
            '',
            [new AllocationRequest($invoice->id(), Money::of('100.00', Currency::EUR))],
        )));
        $this->assertRefused(PaymentRefused::CURRENCY_MISMATCH, fn (): Payment => $this->record->record($this->request('100.00', [new AllocationRequest($invoice->id(), Money::of('100.00', Currency::EUR))])));
        $this->assertNothingStored();
    }

    public function testATrashedInvoiceIsRefused(): void
    {
        $invoice = $this->issue->issue($this->input());
        $this->trash->trash($invoice->id(), 'Issued by mistake');

        $this->assertRefused(PaymentRefused::TRASHED, fn (): Payment => $this->pay($invoice, '100.00'));
        $this->assertNothingStored();
    }

    public function testAFutureDateIsRefused(): void
    {
        $invoice = $this->issue->issue($this->input());

        $this->assertRefused(PaymentRefused::FUTURE_DATE, fn (): Payment => $this->pay($invoice, '100.00', on: '2026-11-22'));
        $this->assertNothingStored();

        // Today in Prague, though still yesterday in UTC.
        $payment = $this->pay($invoice, '100.00', on: '2026-11-21');
        $this->em->clear();
        self::assertSame('2026-11-21', $this->payments->get($payment->id())->receivedOn()->format('Y-m-d'));
    }

    public function testAnUnknownInvoiceIsNotFound(): void
    {
        $this->expectException(\App\Invoicing\Application\UnknownInvoice::class);
        $this->record->record($this->request('100.00', [new AllocationRequest('01963c5e-0000-7000-8000-0000000000ff', $this->czk('100.00'))]));
    }

    public function testCancellingRemovesAllocationsKeepsThePaymentAndIsIdempotent(): void
    {
        Clock::set(new MockClock('2026-11-20 10:00:00', 'UTC'));
        $invoice = $this->issue->issue($this->input());
        $payment = $this->pay($invoice, '1210.00');
        Clock::set(new MockClock('2026-11-20 11:00:00', 'UTC'));

        $this->cancel->cancel($payment->id());
        $this->cancel->cancel($payment->id());

        $this->em->clear();
        $connection = $this->em->getConnection();
        self::assertSame(0, self::int($connection->fetchOne('SELECT COUNT(*) FROM payment_allocation')));
        $stored = $this->payments->get($payment->id());
        self::assertTrue($stored->isCancelled());
        self::assertSame('2026-11-20 11:00', $stored->cancelledAt()?->format('Y-m-d H:i'));
        self::assertSame(121000, $stored->amount()->minor());
        $invoice = $this->store->get($invoice->id());
        self::assertSame(0, $this->payments->paidOf($invoice)->minor());
        self::assertSame([], $this->payments->linesOf($invoice));
        self::assertSame(InvoiceStatus::Overdue, $this->statusOf($invoice));
    }

    public function testCancellingABankPaymentCallsTheRelease(): void
    {
        $invoice = $this->issue->issue($this->input());
        $bank = $this->record->record($this->request('1210.00', [new AllocationRequest($invoice->id(), $this->czk('1210.00'))], source: PaymentSource::BankMovement, movement: self::MOVEMENT));
        $manual = $this->pay($invoice, '10.00');
        $release = new class implements PaymentSourceRelease {
            /** @var list<array{string, string, ?string, bool}> */
            public array $calls = [];

            public function lockSource(Payment $payment): void
            {
                $this->calls[] = ['lock', $payment->id(), $payment->bankMovementId(), $payment->isCancelled()];
            }

            public function released(Payment $payment): void
            {
                $this->calls[] = ['released', $payment->id(), $payment->bankMovementId(), $payment->isCancelled()];
            }
        };
        $container = self::getContainer();
        $cancel = new CancelPayment($this->payments, $this->store, $container->get(TransactionalWork::class), $container->get(ConflictRetry::class), $container->get(AuditActor::class), Clock::get(), $release, ...$this->taxDocumentParts());

        $cancel->cancel($manual->id());
        $cancel->cancel($bank->id());
        $cancel->cancel($bank->id());

        self::assertSame([
            ['lock', $manual->id(), null, false],
            ['lock', $bank->id(), self::MOVEMENT, false],
            ['released', $bank->id(), self::MOVEMENT, true],
            ['lock', $bank->id(), self::MOVEMENT, true],
        ], $release->calls, 'the source is locked first on every call, and only a bank payment cancelled now is released');
        $this->em->clear();
        self::assertSame(PaymentSource::BankMovement, $this->payments->get($bank->id())->source());
        self::assertSame([], $this->payments->linesOf($this->store->get($invoice->id())));
    }

    public function testABankPaymentNamesItsMovementAndOnlyABankPaymentDoes(): void
    {
        $invoice = $this->issue->issue($this->input());

        $this->assertRefused(PaymentRefused::NO_MOVEMENT, fn (): Payment => $this->record->record($this->request('10.00', [new AllocationRequest($invoice->id(), $this->czk('10.00'))], source: PaymentSource::BankMovement)));
        $this->assertRefused(PaymentRefused::NO_MOVEMENT, fn (): Payment => $this->record->record($this->request('10.00', [new AllocationRequest($invoice->id(), $this->czk('10.00'))], movement: self::MOVEMENT)));
        $this->assertNothingStored();

        $payment = $this->record->record($this->request('10.00', [new AllocationRequest($invoice->id(), $this->czk('10.00'))], source: PaymentSource::BankMovement, movement: self::MOVEMENT));
        $lines = $this->payments->linesOf($this->reloaded($invoice));
        self::assertSame([[$payment->id(), PaymentSource::BankMovement, self::MOVEMENT]], array_map(static fn (\App\Invoicing\Application\InvoicePaymentLine $line): array => [$line->paymentId, $line->source, $line->bankMovementId], $lines));
    }

    public function testAMovementPaysThroughOneActivePaymentOnly(): void
    {
        $invoice = $this->issue->issue($this->input());
        $bank = fn (): Payment => $this->record->record($this->request('10.00', [new AllocationRequest($invoice->id(), $this->czk('10.00'))], source: PaymentSource::BankMovement, movement: self::MOVEMENT));
        $first = $bank();

        try {
            $bank();
            self::fail('A second active payment of one movement was stored.');
        } catch (UniqueConstraintViolationException) {
        }

        // Once the first is cancelled, the movement may pay again.
        $this->em->clear();
        $cancelled = $this->em->find(Payment::class, $first->id());
        self::assertInstanceOf(Payment::class, $cancelled);
        $cancelled->cancel(new \DateTimeImmutable('2026-11-20 10:00:00'), null);
        $this->em->flush();
        self::assertNull($this->em->getConnection()->fetchOne('SELECT live_bank_movement_id FROM payment WHERE id = ?', [$first->id()]));
        $bank();
        self::assertEquals(2, $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM payment WHERE bank_movement_id = ?', [self::MOVEMENT]));
    }

    public function testTrashIsRefusedWhilePaymentsExist(): void
    {
        $invoice = $this->issue->issue($this->input());
        $payment = $this->pay($invoice, '100.00');

        $this->assertRefused(PaymentRefused::HAS_PAYMENTS, fn (): Invoice => $this->trash->trash($invoice->id(), 'Issued by mistake'));
        self::assertFalse($this->reloaded($invoice)->isTrashed());

        $this->cancel->cancel($payment->id());
        $this->trash->trash($invoice->id(), 'Issued by mistake');
        self::assertTrue($this->reloaded($invoice)->isTrashed());
    }

    public function testRaisingTheTotalAfterPaymentTurnsPaidIntoPartlyPaid(): void
    {
        $invoice = $this->issue->issue($this->input());
        $this->pay($invoice, '1210.00');
        self::assertSame(InvoiceStatus::Paid, $this->statusOf($this->reloaded($invoice)));

        $this->edited($invoice, $this->input([$this->line(price: '2000.00')]));

        $raised = $this->reloaded($invoice);
        self::assertSame(242000, $raised->payable()->minor());
        self::assertSame(PaymentState::PartlyPaid, PaymentState::of($raised->payable(), $this->payments->paidOf($raised)));

        $this->edited($invoice, $this->input([$this->line(price: '500.00')]));

        $lowered = $this->reloaded($invoice);
        self::assertSame(InvoiceStatus::Overpaid, $this->statusOf($lowered));
        self::assertSame(121000, $this->payments->paidOf($lowered)->minor());
    }

    public function testChangingTheVsAfterPairingKeepsThePayment(): void
    {
        $invoice = $this->issue->issue($this->input());
        $payment = $this->record->record($this->request('1210.00', [new AllocationRequest($invoice->id(), $this->czk('1210.00'))], source: PaymentSource::BankMovement, movement: self::MOVEMENT));

        $this->edited($invoice, $this->input(overrides: ['variableSymbol' => '99990001']));

        $stored = $this->reloaded($invoice);
        self::assertSame('99990001', $stored->variableSymbol());
        self::assertSame(InvoiceStatus::Paid, $this->statusOf($stored));
        self::assertSame([$payment->id()], array_map(static fn (\App\Invoicing\Application\InvoicePaymentLine $line): string => $line->paymentId, $this->payments->linesOf($stored)));
        self::assertFalse($this->payments->get($payment->id())->isCancelled());
    }

    public function testAnotherTenantsPaymentIsNotFound(): void
    {
        $invoice = $this->issue->issue($this->input());
        $payment = $this->pay($invoice, '100.00');
        $context = self::getContainer()->get(TenantContext::class);
        $context->switchTo(TenantId::generate());
        $this->em->clear();

        try {
            $this->payments->get($payment->id());
            self::fail('Another tenant read the payment.');
        } catch (UnknownPayment) {
        }
        try {
            $this->cancel->cancel($payment->id());
            self::fail('Another tenant cancelled the payment.');
        } catch (UnknownPayment) {
        }
        self::assertSame([], $this->payments->paidFor([$invoice->id()]));

        $context->switchTo($invoice->tenantId());
        self::assertFalse($this->payments->get($payment->id())->isCancelled());
    }

    public function testHistoryShowsPaidAndCancelled(): void
    {
        $clock = new MockClock('2026-11-20 10:00:00', 'UTC');
        Clock::set($clock);
        $invoice = $this->issue->issue($this->input());
        $clock->sleep(60);
        $payment = $this->pay($invoice, '1210.00');
        $clock->sleep(60);
        $this->cancel->cancel($payment->id());

        $history = self::getContainer()->get(InvoiceHistory::class)->of($this->reloaded($invoice));

        self::assertSame(
            [InvoiceHistoryEntry::CREATED, InvoiceHistoryEntry::PAID, InvoiceHistoryEntry::PAYMENT_CANCELLED],
            array_map(static fn (InvoiceHistoryEntry $entry): string => $entry->event, $history),
        );
        self::assertSame(['2026-11-20 10:01', '2026-11-20 10:02'], [$history[1]->occurredAt->format('Y-m-d H:i'), $history[2]->occurredAt->format('Y-m-d H:i')]);
        self::assertSame([[], []], [$history[1]->fields, $history[2]->fields]);
        self::assertSame([null, 121000, 121000], array_map(static fn (InvoiceHistoryEntry $entry): ?int => $entry->amountMinor, $history));
    }

    public function testHistoryAddsUpEveryAllocationOfAPaymentWithItsSettlement(): void
    {
        $clock = new MockClock('2026-11-20 10:00:00', 'UTC');
        Clock::set($clock);
        $invoice = $this->issue->issue($this->input());
        $other = $this->issue->issue($this->input());
        $clock->sleep(60);
        $this->record->record($this->request('300.00', [
            new AllocationRequest($invoice->id(), $this->czk('200.00')),
            new AllocationRequest($other->id(), $this->czk('25.00')),
            new AllocationRequest($invoice->id(), $this->czk('75.00')),
        ]));
        $clock->sleep(60);
        $settled = $this->record->record($this->request('934.50', [new AllocationRequest($invoice->id(), $this->czk('934.50'), $this->czk('0.50'))]));
        $clock->sleep(60);
        $this->cancel->cancel($settled->id());

        $history = self::getContainer()->get(InvoiceHistory::class)->of($this->reloaded($invoice));

        self::assertSame(
            [[InvoiceHistoryEntry::CREATED, null], [InvoiceHistoryEntry::PAID, 27500], [InvoiceHistoryEntry::PAID, 93500], [InvoiceHistoryEntry::PAYMENT_CANCELLED, 93500]],
            array_map(static fn (InvoiceHistoryEntry $entry): array => [$entry->event, $entry->amountMinor], $history),
        );
    }

    private function recordingWith(LockLog $log): RecordPayment
    {
        $container = self::getContainer();
        // The one the container wires, as phpstan's container inlines it.
        $taxDocuments = new \ReflectionProperty(RecordPayment::class, 'taxDocuments')->getValue($container->get(RecordPayment::class));
        self::assertInstanceOf(IssuePaymentTaxDocument::class, $taxDocuments);

        return new RecordPayment(
            new LoggingInvoiceStore($this->store, $log),
            $this->payments,
            $container->get(InvoicingCountries::class),
            $container->get(TransactionalWork::class),
            $container->get(ConflictRetry::class),
            $container->get(AuditActor::class),
            Clock::get(),
            $taxDocuments,
            $container->get(EventDispatcherInterface::class),
        );
    }

    private function pay(Invoice $invoice, string $amount, string $on = '2026-11-20', string $note = '', PaymentMethod $method = PaymentMethod::BankTransfer): Payment
    {
        return $this->record->record($this->request($amount, [new AllocationRequest($invoice->id(), $this->czk($amount))], on: $on, note: $note, method: $method));
    }

    /**
     * @param list<AllocationRequest> $allocations
     */
    private function request(
        string $amount,
        array $allocations,
        string $on = '2026-11-20',
        string $note = '',
        PaymentMethod $method = PaymentMethod::BankTransfer,
        PaymentSource $source = PaymentSource::Manual,
        ?string $movement = null,
    ): PaymentRequest {
        return new PaymentRequest($this->firm->id(), new \DateTimeImmutable($on), $this->czk($amount), $method, $source, $movement, $note, $allocations);
    }

    private function czk(string $amount): Money
    {
        return Money::of($amount, Currency::CZK);
    }

    private function statusOf(Invoice $invoice): InvoiceStatus
    {
        return InvoiceStatus::ofInvoice($invoice, new \DateTimeImmutable('2026-11-21'), $this->payments->paidOf($invoice));
    }

    private function assertRefused(string $reason, callable $save): void
    {
        try {
            $save();
        } catch (PaymentRefused $refused) {
            self::assertSame($reason, $refused->reason);
            self::assertSame('The payment was refused: '.$reason.'.', $refused->getMessage());

            return;
        }
        self::fail('Expected the payment to be refused for '.$reason.'.');
    }

    private function assertNothingStored(): void
    {
        $connection = $this->em->getConnection();
        self::assertSame([0, 0], [self::int($connection->fetchOne('SELECT COUNT(*) FROM payment')), self::int($connection->fetchOne('SELECT COUNT(*) FROM payment_allocation'))]);
    }

    private static function int(mixed $value): int
    {
        self::assertIsNumeric($value);

        return (int) $value;
    }

    /**
     * What CancelPayment needs for the tax documents of a payment, as the container wires it.
     *
     * @return array{AdvanceDocuments, PaymentTaxDocumentTexts}
     */
    private function taxDocumentParts(): array
    {
        $wired = self::getContainer()->get(CancelPayment::class);
        $documents = new \ReflectionProperty(CancelPayment::class, 'documents')->getValue($wired);
        $texts = new \ReflectionProperty(CancelPayment::class, 'texts')->getValue($wired);
        self::assertInstanceOf(AdvanceDocuments::class, $documents);
        self::assertInstanceOf(PaymentTaxDocumentTexts::class, $texts);

        return [$documents, $texts];
    }
}
