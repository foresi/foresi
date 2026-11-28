<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Integration\Country;

use Foresi\Invoicing\Application\AllocationRequest;
use Foresi\Invoicing\Application\CancelPayment;
use Foresi\Invoicing\Application\InvoiceFilter;
use Foresi\Invoicing\Application\InvoicePage;
use Foresi\Invoicing\Application\InvoicePayments;
use Foresi\Invoicing\Application\InvoiceRow;
use Foresi\Invoicing\Application\InvoiceStore;
use Foresi\Invoicing\Application\PaymentRequest;
use Foresi\Invoicing\Application\PaymentState;
use Foresi\Invoicing\Application\Query\InvoiceQueries;
use Foresi\Invoicing\Application\Query\InvoiceSliceFilter;
use Foresi\Invoicing\Application\RecordPayment;
use Foresi\Invoicing\Application\View\InvoiceLists;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Contract\PaymentMethod;
use Foresi\Invoicing\Contract\PriceType;
use Foresi\Invoicing\Domain\Invoice;
use Foresi\Invoicing\Domain\Payment;
use Foresi\Invoicing\Domain\PaymentSource;
use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Tests\Invoicing\Application\InvoiceTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

/**
 * The paid amounts as the list reads them: in SQL, for the tabs and for overdue,
 * and in one query for the rows of a page.
 */
final class InvoicePaymentsStoreTest extends InvoiceTestCase
{
    private RecordPayment $record;
    private InvoicePayments $payments;

    protected function setUp(): void
    {
        parent::setUp();
        Clock::set(new MockClock('2026-11-20 10:00:00', 'UTC'));
        $this->record = self::getContainer()->get(RecordPayment::class);
        $this->payments = self::getContainer()->get(InvoicePayments::class);
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testTabsFilterPaidUnpaidAndOverdueExcludesPaid(): void
    {
        $unpaid = $this->issued('Unpaid');
        $paid = $this->issued('Paid');
        $partly = $this->issued('Partly');
        $over = $this->issued('Over');
        $later = $this->issued('Later', '2026-12-15');
        $trashed = $this->issued('Trashed');
        $cancelled = $this->issued('Cancelled');
        $settled = $this->issued('Settled', type: PriceType::WithVat);
        $this->pay($paid, '1210.00');
        $this->pay($partly, '600.00');
        $this->pay($over, '1300.00');
        $this->record->record($this->request('999.50', [new AllocationRequest($settled->id(), $this->czk('999.50'), $this->czk('0.50'))]));
        self::getContainer()->get(CancelPayment::class)->cancel($this->pay($cancelled, '1210.00')->id());
        $this->trash->trash($trashed->id(), 'Issued by mistake');
        $this->em->clear();

        $all = $this->page(InvoiceFilter::ALL);
        self::assertSame(['all' => 7, 'paid' => 3, 'unpaid' => 4, 'overdue' => 3, 'to_refund' => 1, 'trash' => 1], $all->tabCounts);
        self::assertSame(['Paid', 'Over', 'Settled'], $this->descriptions(InvoiceFilter::PAID));
        self::assertSame(['Unpaid', 'Partly', 'Later', 'Cancelled'], $this->descriptions(InvoiceFilter::UNPAID));
        self::assertSame(['Unpaid', 'Partly', 'Cancelled'], $this->descriptions(InvoiceFilter::OVERDUE));
        self::assertSame(['Trashed'], $this->descriptions(InvoiceFilter::TRASH));
        // The views count what the SQL of the tabs they replaced counts, which the API's slice still runs (saved views, D12).
        $queries = self::getContainer()->get(InvoiceQueries::class);
        foreach ([InvoiceFilter::ALL, InvoiceFilter::PAID, InvoiceFilter::UNPAID, InvoiceFilter::OVERDUE] as $tab) {
            $slice = $queries->slice(new InvoiceSliceFilter(DocumentKind::Invoice, state: InvoiceFilter::ALL === $tab ? null : $tab), null, 100);
            self::assertCount($all->tabCounts[$tab], $slice->items, $tab);
        }
        self::assertCount($all->tabCounts[InvoiceFilter::TRASH], $queries->slice(new InvoiceSliceFilter(DocumentKind::Invoice, trashed: true), null, 100)->items);
        // OR within a field: paid or unpaid is every document out of the trash.
        $either = $this->store->page(new InvoiceFilter(InvoiceFilter::ALL, '', 'issueDate', 1, view: ViewFilter::all(new Condition(InvoiceLists::PAYMENT, 'is_any_of', [InvoiceFilter::PAID, InvoiceFilter::UNPAID])), counted: ['neither' => ViewFilter::all(new Condition(InvoiceLists::PAYMENT, 'is_none_of', [InvoiceFilter::PAID, InvoiceFilter::UNPAID]))]));
        self::assertSame([7, ['neither' => 0]], [$either->total, $either->tabCounts]);

        $rows = [];
        foreach ($all->rows as $row) {
            $rows[$row->id] = [$row->paid->minor(), $row->payment, $row->overdue];
        }
        self::assertSame([
            $unpaid->id() => [0, PaymentState::Unpaid, true],
            $paid->id() => [121000, PaymentState::Paid, false],
            $partly->id() => [60000, PaymentState::PartlyPaid, true],
            $over->id() => [130000, PaymentState::Overpaid, false],
            $later->id() => [0, PaymentState::Unpaid, false],
            $cancelled->id() => [0, PaymentState::Unpaid, true],
            $settled->id() => [100000, PaymentState::Paid, false],
        ], $rows);
    }

    public function testAZeroPayableInvoiceIsUnpaidAndStaysOverdue(): void
    {
        $zero = $this->issued('Zero', price: '0.00');
        $this->em->clear();

        self::assertSame(0, $zero->payable()->minor());
        self::assertSame(['Zero'], $this->descriptions(InvoiceFilter::UNPAID));
        self::assertSame(['Zero'], $this->descriptions(InvoiceFilter::OVERDUE));
        self::assertSame([], $this->descriptions(InvoiceFilter::PAID));
    }

    public function testPaidForTakesOneQueryFor25Rows(): void
    {
        $ids = [];
        for ($i = 0; $i < InvoiceStore::PER_PAGE; ++$i) {
            $invoice = $this->issued('Invoice '.$i);
            if (0 === $i % 2) {
                $this->pay($invoice, '100.00');
            }
            $ids[] = $invoice->id();
        }
        $this->em->clear();

        $before = $this->queryCount();
        $paid = $this->payments->paidFor($ids);
        self::assertSame(1, $this->queryCount() - $before);
        self::assertCount(13, $paid);
        self::assertSame([10000], array_values(array_unique($paid)));
        self::assertSame([], $this->payments->paidFor([]));

        $this->em->clear();
        $before = $this->queryCount();
        $page = $this->page(InvoiceFilter::ALL);
        $used = $this->queryCount() - $before;
        self::assertCount(25, $page->rows);
        // The firms, the counts, the rows, the send marks and the paid amounts.
        self::assertLessThanOrEqual(5, $used, 'Queries used: '.$used);
    }

    private function issued(string $description, string $due = '2026-11-15', string $price = '1000.00', PriceType $type = PriceType::WithoutVat): Invoice
    {
        return $this->issue->issue($this->input([$this->line(price: $price, type: $type)], ['description' => $description, 'dueDate' => new \DateTimeImmutable($due)]));
    }

    private function pay(Invoice $invoice, string $amount): Payment
    {
        return $this->record->record($this->request($amount, [new AllocationRequest($invoice->id(), $this->czk($amount))]));
    }

    /**
     * @param list<AllocationRequest> $allocations
     */
    private function request(string $amount, array $allocations): PaymentRequest
    {
        return new PaymentRequest(new \DateTimeImmutable('2026-11-20'), $this->czk($amount), PaymentMethod::BankTransfer, PaymentSource::Manual, null, '', $allocations);
    }

    private function czk(string $amount): Money
    {
        return Money::of($amount, Currency::of('CZK'));
    }

    private function page(string $tab): InvoicePage
    {
        return $this->store->page(new InvoiceFilter($tab, '', 'issueDate', 1));
    }

    /**
     * @return list<string>
     */
    private function descriptions(string $tab): array
    {
        $ids = array_map(static fn (InvoiceRow $row): string => $row->id, $this->page($tab)->rows);
        $this->em->clear();

        return array_map(fn (string $id): string => $this->store->get($id)->description(), $ids);
    }

    private function queryCount(): int
    {
        return \count(self::getContainer()->get('doctrine.debug_data_holder')->getData()['default'] ?? []);
    }
}
