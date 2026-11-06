<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Invoicing\Application\Debtor;
use App\Invoicing\Application\DocumentExports;
use App\Invoicing\Application\InvoicingOverview;
use App\Invoicing\Application\MonthInvoiced;
use App\Invoicing\Application\PendingExport;
use App\Invoicing\Domain\Invoice;
use App\Party\Domain\Address;
use App\Party\Domain\AddressType;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;
use App\Party\Domain\PartyAddress;
use App\Party\Domain\PartyKind;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatId;
use App\Party\Domain\VatStatus;
use App\Shared\Domain\Money;
use App\Tests\Party\Support\FirmAccounts;

/**
 * Today is 20 November 2026, so the last month is October.
 */
final class InvoicingOverviewTest extends AdvanceTestCase
{
    private const string SECOND_IBAN = 'CZ5508000000001234567899';

    private InvoicingOverview $overview;

    protected function setUp(): void
    {
        parent::setUp();
        $this->overview = self::getContainer()->get(InvoicingOverview::class);
    }

    public function testTheMonthsAddUpTheTaxDocumentsByTheirTaxPointWithCorrectionsSubtracted(): void
    {
        $november = $this->invoice('1000.00', '2026-11-30', taxPoint: '2026-11-01');
        $this->invoice('2000.00', '2026-11-30', taxPoint: '2026-10-10');
        $this->invoice('4000.00', '2026-11-30', taxPoint: '2026-09-30');
        $this->trash->trash($this->invoice('8000.00', '2026-11-30', taxPoint: '2026-11-02')->id(), 'Wrong customer');
        $this->correctionOf($november, '-500.00');
        // An advance is no tax document: it stays out of the months.
        $this->advance([$this->line('3000.00')], ['issueDate' => new \DateTimeImmutable('2026-11-05'), 'taxPointDate' => new \DateTimeImmutable('2026-11-05')]);
        $this->em->flush();

        $summary = $this->overview->of(null);

        self::assertSame('2026-11', $summary->thisMonth->month);
        self::assertSame([2, 50000, 10500, 60500], $this->figures($summary->thisMonth));
        self::assertSame('2026-10', $summary->lastMonth->month);
        self::assertSame([1, 200000, 42000, 242000], $this->figures($summary->lastMonth));
    }

    public function testOverdueSplitsByDaysPastTheDueDateAtThirtyAndSixty(): void
    {
        $this->invoice('100.00', '2026-10-21');
        $this->invoice('200.00', '2026-10-20');
        $this->invoice('300.00', '2026-09-21');
        $this->invoice('400.00', '2026-09-20');
        $this->invoice('500.00', '2026-11-20');
        $this->invoice('600.00', '2026-11-19');
        $partly = $this->invoice('1000.00', '2026-12-31');
        $this->pay($partly, '210.00');
        $paid = $this->invoice('700.00', '2026-09-01');
        $this->pay($paid, '847.00');
        $this->trash->trash($this->invoice('900.00', '2026-09-01')->id(), 'Wrong customer');
        $this->advance([$this->line('50.00')], ['dueDate' => new \DateTimeImmutable('2026-08-01')]);
        $this->em->flush();

        $summary = $this->overview->of(null);

        // The 50 advance asks for 61 rounded. Not yet due: 500 on its due day, and what the partly paid one leaves.
        self::assertSame([8, 254100 + 100000 + 6100], [$summary->unpaidCount, $summary->unpaid->minor()]);
        self::assertSame([6, 84700 + 60500 + 48400 + 6100], [$summary->overdueCount, $summary->overdue->minor()]);
        self::assertSame([84700, 60500, 48400 + 6100], array_map(static fn (Money $money): int => $money->minor(), $summary->overdueByAge));
    }

    public function testTheFiveCustomersWhoOweMostComeFirstByNameOnATie(): void
    {
        foreach (['Alpha' => ['100.00', '100.00'], 'Beta' => ['300.00'], 'Gamma' => ['50.00'], 'Delta' => ['300.00'], 'Epsilon' => ['400.00'], '42' => ['10.00']] as $name => $prices) {
            // A name that is a number stays a name.
            $customer = $this->customerNamed('42' === (string) $name ? '42' : $name.' Example a.s.');
            foreach ($prices as $price) {
                $this->invoice($price, '2026-12-31', $customer);
            }
        }
        $this->em->flush();

        $debtors = $this->overview->of(null)->debtors;

        self::assertSame([
            ['Epsilon Example a.s.', 48400, 1],
            ['Beta Example a.s.', 36300, 1],
            ['Delta Example a.s.', 36300, 1],
            ['Alpha Example a.s.', 24200, 2],
            ['Gamma Example a.s.', 6100, 1],
        ], array_map(static fn (Debtor $debtor): array => [$debtor->name, $debtor->amount->minor(), $debtor->count], $debtors));
    }

    public function testTheLastMonthIsRemindedOnlyWhileSomethingWaitsForTheAccountant(): void
    {
        $october = $this->invoice('1000.00', '2026-11-30', taxPoint: '2026-10-10');
        $advance = $this->advance([$this->line('100.00')], ['issueDate' => new \DateTimeImmutable('2026-10-12'), 'taxPointDate' => new \DateTimeImmutable('2026-10-12')]);
        $this->invoice('1000.00', '2026-11-30', taxPoint: '2026-11-10');
        $this->em->flush();

        self::assertEquals([new PendingExport($this->firm->id(), 'Example Firm s.r.o.', '2026-10', 2)], $this->overview->of(null)->pending);

        self::getContainer()->get(DocumentExports::class)->markExported([$october->id(), $advance->id()]);

        self::assertSame([], $this->overview->of(null)->pending);
    }

    public function testAFirmNarrowsEverythingAndAnUnknownOneCoversAll(): void
    {
        $this->invoice('1000.00', '2026-11-30', taxPoint: '2026-10-10');
        $second = $this->secondFirm();
        $this->invoice('100.00', '2026-11-30', taxPoint: '2026-10-10', firm: $second);
        $this->em->flush();

        self::assertSame([$this->firm->id() => 'Example Firm s.r.o.', $second->id() => 'Example Second s.r.o.'], $this->overview->firms());

        $narrowed = $this->overview->of($second->id());
        self::assertSame($second->id(), $narrowed->firmId);
        self::assertSame([1, 10000, 2100, 12100], $this->figures($narrowed->lastMonth));
        self::assertSame(12100, $narrowed->unpaid->minor());
        self::assertEquals([new PendingExport($second->id(), 'Example Second s.r.o.', '2026-10', 1)], $narrowed->pending);

        $all = $this->overview->of('0190a1b2-0000-7000-8000-000000000000');
        self::assertNull($all->firmId);
        self::assertSame(133100, $all->unpaid->minor());
        self::assertCount(2, $all->pending);
    }

    public function testNothingIssuedGivesZeros(): void
    {
        $summary = $this->overview->of(null);

        self::assertSame([], $this->overview->firms());
        self::assertSame([0, 0, 0, 0], $this->figures($summary->thisMonth));
        self::assertSame([0, 0, 0, 0], [$summary->unpaidCount, $summary->unpaid->minor(), $summary->overdueCount, $summary->overdue->minor()]);
        self::assertSame([], $summary->debtors);
        self::assertSame([], $summary->pending);
    }

    /**
     * @return array{int, int, int, int} count, base, VAT and total in minor units
     */
    private function figures(MonthInvoiced $month): array
    {
        return [$month->count, $month->base->minor(), $month->vat->minor(), $month->total->minor()];
    }

    private function invoice(string $price, string $due, ?Party $customer = null, string $taxPoint = '2026-09-01', ?LegalEntity $firm = null): Invoice
    {
        $date = new \DateTimeImmutable($taxPoint);

        return $this->issue->issue($this->input([$this->line($price)], [
            'firmId' => ($firm ?? $this->firm)->id(),
            'seriesId' => $firm instanceof LegalEntity ? null : $this->series->id(),
            'customerId' => ($customer ?? $this->customer)->id(),
            'issueDate' => $date,
            'taxPointDate' => $date,
            'dueDate' => new \DateTimeImmutable($due),
            'variableSymbol' => '',
            'variableSymbolFollowsNumber' => true,
            'bankAccount' => $firm instanceof LegalEntity ? self::SECOND_IBAN : self::IBAN,
        ]));
    }

    private function customerNamed(string $name): Party
    {
        $party = new Party(PartyKind::Company, $name, new RegistrationNumber('CZ', '87654320'), null, '');
        $this->em->persist($party);
        $this->em->persist(new PartyAddress($party, AddressType::Billing, new Address('Customer Street 2', 'Customer City', '200 00', 'CZ')));
        $this->em->flush();

        return $party;
    }

    private function secondFirm(): LegalEntity
    {
        $firm = new LegalEntity('Example Second s.r.o.', new RegistrationNumber('CZ', '12345687'), VatId::fromString('CZ12345687'), new Address('Example Street 2', 'Example City', '100 00', 'CZ'), VatStatus::Registered, 'Example register, entry C 2');
        $this->em->persist($firm);
        FirmAccounts::add($this->em, $firm, self::SECOND_IBAN);
        $this->em->flush();

        return $firm;
    }
}
