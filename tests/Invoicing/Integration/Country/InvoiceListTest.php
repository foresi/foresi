<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Integration\Country;

use Foresi\Invoicing\Application\AllocationRequest;
use Foresi\Invoicing\Application\InvoiceFilter;
use Foresi\Invoicing\Application\InvoicePage;
use Foresi\Invoicing\Application\InvoiceRow;
use Foresi\Invoicing\Application\InvoiceSums;
use Foresi\Invoicing\Application\InvoicingNotAllowed;
use Foresi\Invoicing\Application\PaymentRequest;
use Foresi\Invoicing\Application\PaymentState;
use Foresi\Invoicing\Application\Query\InvoiceList;
use Foresi\Invoicing\Application\Query\InvoiceListItem;
use Foresi\Invoicing\Application\Query\InvoiceSlice;
use Foresi\Invoicing\Application\Query\InvoiceSliceFilter;
use Foresi\Invoicing\Application\RecordPayment;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Domain\Invoice;
use Foresi\Invoicing\Domain\PaymentSource;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\AddressType;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyAddress;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\PaymentMethod;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Tests\Invoicing\Application\Query\InvoicingReadTestCase;

final class InvoiceListTest extends InvoicingReadTestCase
{
    public function testASliceIsNewestFirstAndHoldsEveryKindOutOfTheTrash(): void
    {
        $first = $this->issued();
        $second = $this->issued();
        $credit = $this->correctionOf($second, '-100.00');
        $trashed = $this->issued();
        $this->trash->trash($trashed->id(), 'Wrong customer');

        $slice = $this->slice(new InvoiceSliceFilter());

        self::assertSame([$credit->id(), $second->id(), $first->id()], $this->ids($slice));
        self::assertFalse($slice->hasMore);
        self::assertNull($slice->nextAfterId());
        self::assertSame([DocumentKind::CorrectionDocument, DocumentKind::Invoice, DocumentKind::Invoice], array_map(static fn (InvoiceListItem $item): DocumentKind => $item->row->kind, $slice->items));
    }

    public function testAnItemCarriesWhatTheApiShows(): void
    {
        $invoice = $this->issued('2026-11-02', '2026-11-30', '2000.00');
        $this->em->clear();

        $item = $this->slice(new InvoiceSliceFilter())->items[0];

        self::assertSame($invoice->id(), $item->row->id);
        self::assertSame($invoice->number(), $item->row->number);
        self::assertSame('Example Firm s.r.o.', $item->row->firmName);
        self::assertSame($this->customer->id(), $item->customerId);
        self::assertSame('Example Customer a.s.', $item->row->customerName);
        self::assertSame('2026-11-02', $item->row->issueDate->format('Y-m-d'));
        self::assertSame('2026-11-30', $item->row->dueDate->format('Y-m-d'));
        self::assertSame('2026-11-02', $item->taxPointDate?->format('Y-m-d'));
        self::assertSame(242000, $item->row->payable->minor());
        self::assertSame(Currency::CZK, $item->row->payable->currency());
        self::assertSame(0, $item->row->paid->minor());
        self::assertSame(242000, $item->row->remaining()->minor());
        self::assertSame(PaymentState::Unpaid, $item->row->payment);
        self::assertFalse($item->row->overdue);
        self::assertFalse($item->row->delivered);
        self::assertFalse($item->row->trashed);
        self::assertSame('2026-11-20 10:00', $item->createdAt->format('Y-m-d H:i'));
    }

    public function testTheCursorExcludesItsOwnRowAndNextDetectionReadsOneMore(): void
    {
        $oldest = $this->issued();
        $middle = $this->issued();
        $newest = $this->issued();

        $first = $this->slice(new InvoiceSliceFilter(), null, 2);
        self::assertSame([$newest->id(), $middle->id()], $this->ids($first));
        self::assertTrue($first->hasMore);
        self::assertSame($middle->id(), $first->nextAfterId());

        $second = $this->slice(new InvoiceSliceFilter(), $first->nextAfterId(), 2);
        self::assertSame([$oldest->id()], $this->ids($second));
        self::assertFalse($second->hasMore);
        self::assertNull($second->nextAfterId());

        self::assertSame([$newest->id()], $this->ids($this->slice(new InvoiceSliceFilter(), null, 1)));
        $exact = $this->slice(new InvoiceSliceFilter(), null, 3);
        self::assertCount(3, $exact->items);
        self::assertFalse($exact->hasMore, 'A slice that takes the last document has no next one.');
        self::assertSame([], $this->ids($this->slice(new InvoiceSliceFilter(), $oldest->id())));
    }

    public function testTheKindNarrowsTheSlice(): void
    {
        $invoice = $this->issued();
        $credit = $this->correctionOf($invoice, '-100.00');

        self::assertSame([$credit->id()], $this->ids($this->slice(new InvoiceSliceFilter(kind: DocumentKind::CorrectionDocument))));
        self::assertSame([$invoice->id()], $this->ids($this->slice(new InvoiceSliceFilter(kind: DocumentKind::Invoice))));
        self::assertSame([], $this->ids($this->slice(new InvoiceSliceFilter(kind: DocumentKind::Advance))));
    }

    public function testTheCustomerNarrowsTheSlice(): void
    {
        $first = $this->issued();
        $other = $this->customer('CZ87654321');
        $this->em->flush();
        $second = $this->issue->issue($this->input(null, ['customerId' => $other->id()]));

        self::assertSame([$first->id()], $this->ids($this->slice(new InvoiceSliceFilter(customerId: $this->customer->id()))));
        self::assertSame([$second->id()], $this->ids($this->slice(new InvoiceSliceFilter(customerId: $other->id()))));
    }

    public function testTheIssueDatesNarrowTheSliceWithBothDaysIncluded(): void
    {
        $early = $this->issued('2026-10-31', '2026-11-30');
        $from = $this->issued('2026-11-01', '2026-11-30');
        $to = $this->issued('2026-11-10', '2026-11-30');
        $late = $this->issued('2026-11-11', '2026-11-30');

        $period = new InvoiceSliceFilter(issuedFrom: new \DateTimeImmutable('2026-11-01'), issuedTo: new \DateTimeImmutable('2026-11-10'));
        self::assertSame([$to->id(), $from->id()], $this->ids($this->slice($period)));
        self::assertSame([$late->id(), $to->id(), $from->id()], $this->ids($this->slice(new InvoiceSliceFilter(issuedFrom: new \DateTimeImmutable('2026-11-01')))));
        self::assertSame([$to->id(), $from->id(), $early->id()], $this->ids($this->slice(new InvoiceSliceFilter(issuedTo: new \DateTimeImmutable('2026-11-10')))));
    }

    public function testTheStateNarrowsTheSlice(): void
    {
        $overdue = $this->issued('2026-11-01', '2026-11-15');
        $unpaid = $this->issued('2026-11-01', '2026-11-30');
        $paid = $this->issued('2026-11-01', '2026-11-30');
        $partly = $this->issued('2026-11-01', '2026-11-30');
        $this->pay($paid, '1210.00');
        $this->pay($partly, '500.00');

        self::assertSame([$partly->id(), $unpaid->id(), $overdue->id()], $this->ids($this->slice(new InvoiceSliceFilter(state: InvoiceFilter::UNPAID))));
        self::assertSame([$overdue->id()], $this->ids($this->slice(new InvoiceSliceFilter(state: InvoiceFilter::OVERDUE))));
        self::assertSame([$paid->id()], $this->ids($this->slice(new InvoiceSliceFilter(state: InvoiceFilter::PAID))));
        self::assertCount(4, $this->slice(new InvoiceSliceFilter())->items);
        $rows = array_column(array_map(static fn (InvoiceListItem $item): array => [$item->row->id, $item->row], $this->slice(new InvoiceSliceFilter())->items), 1, 0);
        self::assertTrue($rows[$overdue->id()]->overdue);
        self::assertSame(PaymentState::Paid, $rows[$paid->id()]->payment);
        self::assertSame(121000, $rows[$paid->id()]->paid->minor());
        self::assertSame(PaymentState::PartlyPaid, $rows[$partly->id()]->payment);
        self::assertSame(71000, $rows[$partly->id()]->remaining()->minor());
    }

    public function testTheTrashIsExcludedUnlessAskedForAndThenAloneInTheSlice(): void
    {
        $kept = $this->issued();
        $trashed = $this->issued();
        $this->trash->trash($trashed->id(), 'Wrong customer');

        self::assertSame([$kept->id()], $this->ids($this->slice(new InvoiceSliceFilter(state: InvoiceFilter::UNPAID))));
        $trash = $this->slice(new InvoiceSliceFilter(trashed: true));
        self::assertSame([$trashed->id()], $this->ids($trash));
        self::assertTrue($trash->items[0]->row->trashed);
        self::assertSame([$trashed->id()], $this->ids($this->slice(new InvoiceSliceFilter(state: InvoiceFilter::PAID, trashed: true))), 'The trash holds what is in it, whatever the state.');
    }

    public function testTheTextFindsTheNumberAndTheCustomerName(): void
    {
        $first = $this->issued();
        $other = new Party(PartyKind::Company, 'Zebra Trading s.r.o.', new RegistrationNumber('CZ', '11223344'), null, '');
        $this->em->persist($other);
        $this->em->persist(new PartyAddress($other, AddressType::Billing, new Address('Zebra Street 3', 'Zebra City', '300 00', 'CZ')));
        $this->em->flush();
        $second = $this->issue->issue($this->input(null, ['customerId' => $other->id(), 'variableSymbol' => '20269999']));

        self::assertSame([$second->id()], $this->ids($this->slice(new InvoiceSliceFilter(text: ' zebra '))));
        self::assertSame([$first->id()], $this->ids($this->slice(new InvoiceSliceFilter(text: $first->number()))));
        self::assertSame([$second->id()], $this->ids($this->slice(new InvoiceSliceFilter(text: $second->number()))));
        self::assertSame([], $this->ids($this->slice(new InvoiceSliceFilter(text: 'nobody'))));
        self::assertCount(2, $this->slice(new InvoiceSliceFilter(text: '   '))->items, 'A blank text is no text.');
        self::assertSame([], $this->ids($this->slice(new InvoiceSliceFilter(text: '%'))), 'A wildcard is looked for as it is.');
    }

    #[\PHPUnit\Framework\Attributes\DoesNotPerformAssertions]
    public function testAGuestAndNobodyAreRefused(): void
    {
        $this->issued();
        $list = $this->list();
        foreach ([fn () => $this->signIn($this->guest), $this->signOut(...)] as $becomes) {
            $becomes();
            foreach ([
                static fn (): InvoiceSlice => $list->slice(new InvoiceSliceFilter(), null, 25),
                static fn (): InvoicePage => $list->page(new InvoiceFilter(InvoiceFilter::ALL, '', InvoiceFilter::DEFAULT_SORT, 1)),
                static fn (): InvoiceSums => $list->sums(new InvoiceFilter(InvoiceFilter::ALL, '', InvoiceFilter::DEFAULT_SORT, 1)),
                static fn (): int => $list->count(new InvoiceSliceFilter()),
            ] as $read) {
                try {
                    $read();
                    self::fail('The read was allowed.');
                } catch (InvoicingNotAllowed) {
                }
            }
        }
    }

    public function testAMemberReadsTheListsAsTheStoreGivesThem(): void
    {
        $invoice = $this->issued();
        $this->signIn($this->member);
        $filter = new InvoiceFilter(InvoiceFilter::ALL, '', InvoiceFilter::DEFAULT_SORT, 1);

        $page = $this->list()->page($filter);
        self::assertSame([$invoice->id()], array_map(static fn (InvoiceRow $row): string => $row->id, $page->rows));
        self::assertSame(1, $page->total);
        $sums = $this->list()->sums($filter);
        self::assertSame(1, $sums->count);
        self::assertSame(100000, $sums->base->minor());
        self::assertSame([$invoice->id()], $this->ids($this->slice(new InvoiceSliceFilter())));
    }

    #[\PHPUnit\Framework\Attributes\DoesNotPerformAssertions]
    public function testAnInvalidAskIsRefused(): void
    {
        $this->issued();

        foreach ([
            fn (): InvoiceSlice => $this->slice(new InvoiceSliceFilter(), null, 0),
            fn (): InvoiceSlice => $this->slice(new InvoiceSliceFilter(), null, -1),
            fn (): InvoiceSlice => $this->slice(new InvoiceSliceFilter(), 'not-an-id'),
            fn (): InvoiceSlice => $this->slice(new InvoiceSliceFilter(), strtoupper('01963c5e-0000-7000-8000-00000000000a')),
            static fn (): InvoiceSliceFilter => new InvoiceSliceFilter(state: 'refunded'),
            static fn (): InvoiceSliceFilter => new InvoiceSliceFilter(state: 'trash'),
            static fn (): InvoiceSliceFilter => new InvoiceSliceFilter(customerId: 'not-an-id'),
        ] as $ask) {
            try {
                $ask();
                self::fail('The ask was taken.');
            } catch (\InvalidArgumentException) {
            }
        }
    }

    private function list(): InvoiceList
    {
        return self::getContainer()->get(InvoiceList::class);
    }

    private function slice(InvoiceSliceFilter $filter, ?string $after = null, int $limit = 25): InvoiceSlice
    {
        $this->em->clear();

        return $this->list()->slice($filter, $after, $limit);
    }

    /**
     * @return list<string>
     */
    private function ids(InvoiceSlice $slice): array
    {
        return array_map(static fn (InvoiceListItem $item): string => $item->row->id, $slice->items);
    }

    private function pay(Invoice $invoice, string $amount): void
    {
        $money = Money::of($amount, Currency::CZK);
        self::getContainer()->get(RecordPayment::class)->record(new PaymentRequest(new \DateTimeImmutable('2026-11-20'), $money, PaymentMethod::BankTransfer, PaymentSource::Manual, null, '', [new AllocationRequest($invoice->id(), $money)]));
    }
}
