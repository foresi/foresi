<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Invoicing\Application\DeductibleAdvances;
use App\Invoicing\Application\DocumentChain;
use App\Invoicing\Application\LineInput;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\Percent;
use App\Invoicing\Domain\PriceType;
use App\Invoicing\Domain\Quantity;
use App\Invoicing\Domain\Series;
use App\Invoicing\Domain\TaxTreatment;
use App\Invoicing\Domain\VatRateKind;
use App\Party\Domain\DocumentLanguage;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;

/**
 * An advance followed from its payment to the final invoice. The golden case runs
 * across a year end with the numbering iDoklad gives such a chain, between example
 * parties: advance Z920240009 of 22 November 2024, paid on 27 December 2024, its
 * tax document D820240009, and final invoice 20250015 of 6 February 2025.
 */
final class AdvanceChainTest extends AdvanceTestCase
{
    public function testTheGoldenChainFromAdvanceToFinalInvoice(): void
    {
        Clock::set(new MockClock('2025-02-06 10:00:00', 'UTC'));
        $this->startSeries(DocumentKind::Advance, '2024', 9);
        $this->startSeries(DocumentKind::PaymentTaxDocument, '2024', 9);
        $this->series->startAt(15, '2025');
        $this->em->flush();

        $advance = $this->advance([$this->gross('37510.00', text: 'First milestone')], ['issueDate' => new \DateTimeImmutable('2024-11-22'), 'dueDate' => new \DateTimeImmutable('2024-12-06')]);
        self::assertSame(['Z920240009', '920240009'], [$advance->number(), $advance->variableSymbol()]);
        self::assertNull($this->reloaded($advance)->taxPointDate());

        $this->pay($advance, '37510.00', '2024-12-27');

        $document = $this->onlyTaxDocumentOf($advance);
        self::assertSame(['D820240009', '2024-12-27', '920240009'], [$document->number(), $document->taxPointDate()?->format('Y-m-d'), $document->variableSymbol()]);
        self::assertSame([['21.00', 3100000, 651000, 3751000]], $this->figuresOf($document));

        $deduction = self::getContainer()->get(DeductibleAdvances::class)->for($this->firm, $this->customer->id(), null, DocumentLanguage::Czech)[0]->lines;
        $work = new LineInput('Development', Quantity::fromString('65'), 'h', Money::of('1200.00', Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0'));
        $final = $this->issue->issue($this->input([$work, ...$deduction], [
            'issueDate' => new \DateTimeImmutable('2025-02-06'),
            'taxPointDate' => new \DateTimeImmutable('2025-02-06'),
            'dueDate' => new \DateTimeImmutable('2025-02-20'),
            'description' => 'Settlement of advance Z920240009',
        ]));

        $stored = $this->reloaded($final);
        self::assertSame('20250015', $stored->number());
        self::assertSame([4700000, 987000, 5687000], [$stored->net()->minor(), $stored->vat()->minor(), $stored->payable()->minor()]);
        self::assertSame('Odpočet zálohy VS 920240009 (daňový doklad D820240009)', $this->store->lines($stored)[1]->text());
        $chain = self::getContainer()->get(DocumentChain::class)->of($this->reloaded($advance));
        self::assertSame(['Z920240009', 'D820240009', '20250015'], [$chain[0]['advance']['number'], $chain[0]['payments'][0]['taxDocument']['number'] ?? null, $chain[0]['finals'][0]['number']]);
    }

    public function testADecemberPaymentIsSettledInJanuary(): void
    {
        Clock::set(new MockClock('2027-01-10 10:00:00', 'UTC'));
        $advance = $this->advance([$this->line('10000.00')], ['issueDate' => new \DateTimeImmutable('2026-12-01'), 'dueDate' => new \DateTimeImmutable('2026-12-15')]);
        $this->pay($advance, '12100.00', '2026-12-30');
        $document = $this->onlyTaxDocumentOf($advance);
        $deduction = self::getContainer()->get(DeductibleAdvances::class)->for($this->firm, $this->customer->id(), null, DocumentLanguage::Czech)[0]->lines;

        $final = $this->issue->issue($this->input([$this->line('15000.00'), ...$deduction], ['issueDate' => new \DateTimeImmutable('2027-01-10'), 'taxPointDate' => new \DateTimeImmutable('2027-01-10'), 'dueDate' => new \DateTimeImmutable('2027-01-24')]));

        // The tax document belongs to December, the difference to January.
        self::assertSame(['Z920260001', 'D820260001', '2026-12-30'], [$advance->number(), $document->number(), $document->taxPointDate()?->format('Y-m-d')]);
        $stored = $this->reloaded($final);
        self::assertSame(['20270001', 500000, 105000, 605000], [$stored->number(), $stored->net()->minor(), $stored->vat()->minor(), $stored->payable()->minor()]);
        self::assertSame([$document->id()], array_values(array_filter(array_map(static fn (\App\Invoicing\Domain\InvoiceLine $line): ?string => $line->deducts(), $this->store->lines($stored)), \is_string(...))));
        $ids = $this->store->idsForPeriod($this->firm, new \DateTimeImmutable('2026-12-01'), new \DateTimeImmutable('2026-12-31'));
        self::assertSame([$document->id()], $ids, 'December holds the tax document only');
    }

    private function startSeries(DocumentKind $kind, string $year, int $number): Series
    {
        $series = Series::standard($this->firm, $kind);
        $series->startAt($number, $year);
        $this->em->persist($series);

        return $series;
    }
}
