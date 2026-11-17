<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Purchasing\Application;

use App\Purchasing\Application\PayReceivedDocument;
use App\Purchasing\Application\PurchasingNotAllowed;
use App\Purchasing\Application\Query\ReceivedDocumentBalances;
use App\Purchasing\Application\Query\ReceivedDocumentSliceFilter;
use App\Purchasing\Application\TrashReceivedDocument;
use App\Purchasing\Application\UnknownReceivedDocument;
use App\Purchasing\Domain\PaymentMethod;
use App\Purchasing\Domain\ReceivedDocumentKind;
use App\Purchasing\Domain\ReceivedDocumentRefused;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use App\Tests\Invoicing\Application\ReadsEventLog;

/**
 * The payments of received documents (decision C3 of the cash flow spec):
 * several per document, never more than its total, "mark as paid" for what
 * remains today in the tenant's zone, the paid event once the document is paid
 * in full, and the recorded event when a document is first recorded.
 */
final class PayReceivedDocumentTest extends PurchasingTestCase
{
    use ReadsEventLog;

    public function testPartialPaymentsAddUpAndTheLastOneRecordsThePaidEvent(): void
    {
        $id = $this->recorded();

        $this->payments()->pay($id, new \DateTimeImmutable('2026-11-12'), Money::of('500.00', Currency::CZK), PaymentMethod::BankTransfer);
        $paid = $this->balances()->of($id);
        self::assertSame(50_000, $paid->balance->paid->minor());
        self::assertSame(71_000, $paid->balance->remaining->minor());
        self::assertFalse($paid->balance->isPaid());
        self::assertSame([], $this->events('purchasing.received_document.paid'));

        $last = $this->payments()->pay($id, new \DateTimeImmutable('2026-11-14'), Money::of('710.00', Currency::CZK), PaymentMethod::Cash);
        $paid = $this->balances()->of($id);
        self::assertTrue($paid->balance->isPaid());
        self::assertSame('2026-11-14', $paid->balance->lastPaidOn?->format('Y-m-d'));
        self::assertCount(2, $paid->payments);
        $events = $this->events('purchasing.received_document.paid');
        self::assertCount(1, $events);
        self::assertSame($id, $events[0]['subject']);
        self::assertSame(['received_document_id' => $id, 'firm_id' => $this->firm->id(), 'payment_id' => $last->id(), 'paid_on' => '2026-11-14'], $events[0]['data']);
    }

    public function testAPaymentIsNeverMoreThanWhatRemains(): void
    {
        $id = $this->recorded();
        $this->assertRefused(ReceivedDocumentRefused::OVERPAID, fn () => $this->payments()->pay($id, new \DateTimeImmutable('2026-11-12'), Money::of('1210.01', Currency::CZK), PaymentMethod::BankTransfer));
        $this->assertRefused(ReceivedDocumentRefused::PAYMENT_AMOUNT, fn () => $this->payments()->pay($id, new \DateTimeImmutable('2026-11-12'), Money::of('0', Currency::CZK), PaymentMethod::BankTransfer));
        $this->assertRefused(ReceivedDocumentRefused::PAYMENT_AMOUNT, fn () => $this->payments()->pay($id, new \DateTimeImmutable('2026-11-12'), Money::of('10.00', Currency::EUR), PaymentMethod::BankTransfer));
        $this->assertRefused(ReceivedDocumentRefused::BANK_MOVEMENT_INVALID, fn () => $this->payments()->pay($id, new \DateTimeImmutable('2026-11-12'), Money::of('10.00', Currency::CZK), PaymentMethod::BankTransfer, 'nope'));

        $this->payments()->markPaid($id, PaymentMethod::Card);
        $this->assertRefused(ReceivedDocumentRefused::PAID, fn () => $this->payments()->markPaid($id, PaymentMethod::Card));
        self::assertCount(1, $this->events('purchasing.received_document.paid'));
    }

    public function testAPaymentRecordedByMistakeIsRemoved(): void
    {
        $id = $this->recorded();
        $wrong = $this->payments()->pay($id, new \DateTimeImmutable('2026-11-12'), Money::of('1200.00', Currency::CZK), PaymentMethod::BankTransfer);

        $this->payments()->remove($id, $wrong->id());

        $paid = $this->balances()->of($id);
        self::assertSame([], $paid->payments);
        self::assertSame(121_000, $paid->balance->remaining->minor());
        $this->expectException(UnknownReceivedDocument::class);
        $this->payments()->remove($id, $wrong->id());
    }

    public function testMarkAsPaidPaysWhatRemainsOnTheTenantsToday(): void
    {
        $id = $this->recorded();
        $this->payments()->pay($id, new \DateTimeImmutable('2026-11-12'), Money::of('210.00', Currency::CZK), PaymentMethod::BankTransfer);
        // 23:30 UTC on 30 November is already 1 December in Prague.
        $this->clock->modify('2026-11-30 23:30:00');

        $payment = $this->payments()->markPaid($id, PaymentMethod::BankTransfer, '01940000-0000-7000-8000-000000000001');

        self::assertSame('2026-12-01', $payment->paidOn()->format('Y-m-d'));
        self::assertSame(100_000, $payment->amount()->minor());
        self::assertSame('01940000-0000-7000-8000-000000000001', $payment->bankMovementId());
        self::assertTrue($this->balances()->of($id)->balance->isPaid());
    }

    public function testJustBeforePragueMidnightItIsStillThatDay(): void
    {
        $id = $this->recorded();
        // 22:59 UTC on 30 November is 23:59 in Prague.
        $this->clock->modify('2026-11-30 22:59:00');

        self::assertSame('2026-11-30', $this->payments()->markPaid($id, PaymentMethod::Cash)->paidOn()->format('Y-m-d'));
    }

    public function testACreditNoteIsPaidBackWithAPositiveAmount(): void
    {
        $original = $this->recorded();
        $note = $this->recording()->create($this->firm->id(), $this->input(kind: ReceivedDocumentKind::CreditNote, supplierNumber: 'DB-1', base: -10_000, originalDocumentId: $original))->document->id();

        $paid = $this->balances()->of($note);
        self::assertSame(12_100, $paid->balance->remaining->minor());
        $this->payments()->pay($note, new \DateTimeImmutable('2026-11-20'), Money::of('121.00', Currency::CZK), PaymentMethod::BankTransfer);
        self::assertTrue($this->balances()->of($note)->balance->isPaid());
    }

    public function testADraftAndADocumentInTheTrashTakeNoPayment(): void
    {
        $draft = $this->draft();
        $this->assertRefused(ReceivedDocumentRefused::NOT_RECORDED, fn () => $this->payments()->markPaid($draft, PaymentMethod::Cash));

        $id = $this->recorded();
        self::getContainer()->get(TrashReceivedDocument::class)->trash($id, 'Recorded twice.');
        $this->assertRefused(ReceivedDocumentRefused::TRASHED, fn () => $this->payments()->markPaid($id, PaymentMethod::Cash));
    }

    public function testAGuestPaysNothing(): void
    {
        $id = $this->recorded();
        $this->signIn($this->guest);

        $this->expectException(PurchasingNotAllowed::class);
        $this->payments()->markPaid($id, PaymentMethod::Cash);
    }

    public function testTheSliceFindsWhatIsUnpaidAndDueBeforeADay(): void
    {
        $paid = $this->recorded('FV-1');
        $open = $this->recorded('FV-2');
        $draft = $this->draft();
        $this->payments()->markPaid($paid, PaymentMethod::Cash);

        $ids = fn (ReceivedDocumentSliceFilter $filter): array => array_map(static fn ($item): string => $item->document->id(), $this->balances()->slice($filter, null, 10)->items);

        self::assertSame([$draft, $open, $paid], $ids(new ReceivedDocumentSliceFilter()));
        self::assertSame([$open], $ids(new ReceivedDocumentSliceFilter(unpaid: true)));
        // The input of the test case is due on 11 November.
        self::assertSame([$open], $ids(new ReceivedDocumentSliceFilter(unpaid: true, dueBefore: new \DateTimeImmutable('2026-11-12'))));
        self::assertSame([], $ids(new ReceivedDocumentSliceFilter(unpaid: true, dueBefore: new \DateTimeImmutable('2026-11-11'))));
        $slice = $this->balances()->slice(new ReceivedDocumentSliceFilter(), null, 2);
        self::assertTrue($slice->hasMore);
        self::assertSame([$paid], array_map(static fn ($item): string => $item->document->id(), $this->balances()->slice(new ReceivedDocumentSliceFilter(), $slice->lastId(), 2)->items));
    }

    public function testRecordingADocumentRecordsItsEventOnceAndADraftNone(): void
    {
        // Uploading a draft records nothing; recording it once does, saving it again does not.
        $draft = $this->draft();
        $this->recording()->record($draft, $this->input());
        $this->editing()->edit($draft, $this->input(supplierNumber: 'FV-2026-124'));

        $events = $this->events('purchasing.received_document.recorded');
        self::assertCount(1, $events);
        self::assertSame(['received_document_id' => $draft, 'firm_id' => $this->firm->id(), 'kind' => 'tax_invoice', 'supplier_id' => $this->supplier->id(), 'total' => '1210.00', 'currency' => 'CZK', 'due_date' => '2026-11-11'], $events[0]['data']);
    }

    private function recorded(string $number = 'FV-2026-123'): string
    {
        return $this->recording()->create($this->firm->id(), $this->input(supplierNumber: $number))->document->id();
    }

    private function payments(): PayReceivedDocument
    {
        return self::getContainer()->get(PayReceivedDocument::class);
    }

    private function balances(): ReceivedDocumentBalances
    {
        return self::getContainer()->get(ReceivedDocumentBalances::class);
    }
}
