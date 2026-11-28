<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Vat\Application;

use Foresi\Party\Contract\VatPeriodLength;
use Foresi\Party\Contract\VatRegistration;
use Foresi\Party\Contract\VatStretch;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Vat\Application\CalculationStep;
use Foresi\Vat\Application\PeriodAmounts;
use Foresi\Vat\Application\ScheduleAmount;
use Foresi\Vat\Application\VatCalculation;
use Foresi\Vat\Contract\DocumentVat;
use Foresi\Vat\Contract\ScheduledVat;
use Foresi\Vat\Contract\VatPeriod;
use Foresi\Vat\Contract\VatSubject;
use PHPUnit\Framework\TestCase;

/**
 * The VAT of each period from the documents, for a payer from 12 November
 * 2026 filing monthly unless a test says otherwise: a document counts in the
 * period of the day it counts on, and only when the firm is a payer on its
 * tax point.
 */
final class VatCalculationTest extends TestCase
{
    public function testMartinsNovember(): void
    {
        $issued = $this->issued('2026-11-20', 210_000);
        $received = $this->received('2026-11-01', '2026-11-24', 21_000);

        [$november, $december] = VatCalculation::compute([$this->month('2026-11', '2026-11-12', '2026-11-30'), $this->month('2026-12', '2026-12-01', '2026-12-31')], [$received, $issued], $this->payer());

        self::assertSame([210_000, 21_000, 189_000], $this->totals($november));
        self::assertSame('CZK', $november->result->currency()->code());
        self::assertSame([$received, $issued], $november->documents);
        self::assertSame([210_000, 21_000], [$november->documentsPart->output->minor(), $november->documentsPart->input->minor()]);
        self::assertSame([0, 0, 0, 0], [$november->schedulesPart->output->minor(), $november->schedulesPart->input->minor(), $november->plansPart->output->minor(), $november->plansPart->input->minor()]);
        self::assertSame([
            [CalculationStep::OUTPUT, 210_000, VatCalculation::RULE_OUTPUT, ['invoicing.issued_vat', 'received-documents.received_vat']],
            [CalculationStep::INPUT, 21_000, VatCalculation::RULE_INPUT, ['invoicing.issued_vat', 'received-documents.received_vat']],
            [CalculationStep::RESULT, 189_000, 'output VAT minus deductible input VAT', ['output', 'input']],
        ], array_map(static fn (CalculationStep $step): array => [$step->name, $step->amount->minor(), $step->rule, $step->sources], $november->steps));
        self::assertSame([[], []], [$november->warnings, $november->missingCzk]);
        self::assertSame([0, 0, 0], $this->totals($december));
        self::assertSame([], $december->documents);
        self::assertSame([], $december->steps[0]->sources);
        self::assertSame([], $december->schedules);
    }

    public function testAReceivedDocumentCountsInItsDeductionMonth(): void
    {
        [$november, $december] = VatCalculation::compute([$this->month('2026-11', '2026-11-12', '2026-11-30'), $this->month('2026-12', '2026-12-01', '2026-12-31')], [$this->received('2026-12-01', '2026-11-28', 21_000)], $this->payer());

        self::assertSame([0, 0, 0], $this->totals($november));
        self::assertSame([0, 21_000, -21_000], $this->totals($december), 'A refund of 210.');
    }

    public function testATaxPointBeforeThePayerDayGivesNoDeduction(): void
    {
        $november = [$this->month('2026-11', '2026-11-12', '2026-11-30')];

        $on = $this->received('2026-11-01', '2026-11-12', 21_000);
        [$period] = VatCalculation::compute($november, [$this->received('2026-11-01', '2026-11-05', 50_000), $this->received('2026-11-01', '2026-11-11', 100), $on], $this->payer());

        self::assertSame([0, 21_000, -21_000], $this->totals($period), 'The payer day itself counts.');
        self::assertSame([$on], $period->documents);
    }

    public function testAStretchEndsTheDayBeforeTheNextBegins(): void
    {
        $stretches = [...$this->payer(), new VatStretch('end', VatRegistration::NotRegistered, null, new \DateTimeImmutable('2027-03-15'))];
        $march = [$this->month('2027-03', '2027-03-01', '2027-03-14')];

        [$last] = VatCalculation::compute($march, [$this->issued('2027-03-14', 100)], $stretches);
        [$after] = VatCalculation::compute($march, [$this->issued('2027-03-15', 100)], $stretches);

        self::assertSame(100, $last->output->minor());
        self::assertSame(0, $after->output->minor());
    }

    public function testADocumentCountsOnceWhenAMonthHasTwoPayerParts(): void
    {
        $stretches = [
            ...$this->payer(),
            new VatStretch('gap', VatRegistration::NotRegistered, null, new \DateTimeImmutable('2027-03-06')),
            new VatStretch('again', VatRegistration::Registered, VatPeriodLength::Month, new \DateTimeImmutable('2027-03-20')),
        ];

        [$first, $second] = VatCalculation::compute([$this->month('2027-03', '2027-03-01', '2027-03-05'), $this->month('2027-03', '2027-03-20', '2027-03-31')], [$this->issued('2027-03-25', 100)], $stretches);

        self::assertSame([100, 0], [$first->output->minor(), $second->output->minor()]);
    }

    public function testAnUndatedPayerStretchCountsNothing(): void
    {
        [$period] = VatCalculation::compute([$this->month('2026-11', '2026-11-01', '2026-11-30')], [$this->issued('2026-11-20', 100)], [new VatStretch('undated', VatRegistration::Registered, VatPeriodLength::Month, null)]);

        self::assertSame(0, $period->output->minor());
    }

    public function testSelfAssessedVatGivesOutputAndInputInTheTaxPointMonth(): void
    {
        $service = new DocumentVat('received-documents.received_vat', new VatSubject('received_document', 'eu', null), 'EU-1', $this->day('2026-11-01'), $this->day('2026-11-20'), $this->czk(105_000), $this->czk(105_000));

        [$november] = VatCalculation::compute([$this->month('2026-11', '2026-11-12', '2026-11-30')], [$service], $this->payer());

        self::assertSame([105_000, 105_000, 0], $this->totals($november));
    }

    public function testACorrectiveCreditLowersOutputInItsOwnPeriod(): void
    {
        [$november, $december] = VatCalculation::compute(
            [$this->month('2026-11', '2026-11-12', '2026-11-30'), $this->month('2026-12', '2026-12-01', '2026-12-31')],
            [$this->issued('2026-11-20', 210_000), $this->issued('2026-12-10', 42_000), $this->issued('2026-12-15', -21_000)],
            $this->payer(),
        );

        self::assertSame(210_000, $november->output->minor());
        self::assertSame(21_000, $december->output->minor());
    }

    public function testAQuarterlyFilerMapsTheDeductionMonthToItsQuarter(): void
    {
        $stretches = [new VatStretch('q', VatRegistration::Registered, VatPeriodLength::Quarter, new \DateTimeImmutable('2026-01-01'))];
        $quarters = [$this->quarter('2026-Q1', '2026-01-01', '2026-03-31'), $this->quarter('2026-Q2', '2026-04-01', '2026-06-30'), $this->quarter('2026-Q4', '2026-10-01', '2026-12-31')];

        [$first, $second, $fourth] = VatCalculation::compute($quarters, [
            $this->received('2026-01-01', '2026-01-10', 1),
            $this->received('2026-03-01', '2026-03-10', 10),
            $this->received('2026-04-01', '2026-03-31', 100),
            $this->received('2026-06-01', '2026-06-10', 1_000),
            $this->received('2026-11-01', '2026-10-28', 10_000),
            $this->received('2026-12-01', '2026-12-31', 100_000),
        ], $stretches);

        self::assertSame([11, 1_100, 110_000], [$first->input->minor(), $second->input->minor(), $fourth->input->minor()]);
    }

    public function testADocumentOutsideThePeriodsCountsNowhere(): void
    {
        [$november] = VatCalculation::compute([$this->month('2026-11', '2026-11-12', '2026-11-30')], [$this->issued('2026-12-01', 100), $this->issued('2027-11-20', 100)], $this->payer());

        self::assertSame([0, []], [$november->output->minor(), $november->documents]);
    }

    public function testMissingCzkVatWarnsAndCountsZero(): void
    {
        $missing = new DocumentVat('received-documents.received_vat', new VatSubject('received_document', 'x', null), 'PD-2026-7', $this->day('2026-11-01'), $this->day('2026-11-20'), $this->czk(0), $this->czk(0), true);

        [$november] = VatCalculation::compute([$this->month('2026-11', '2026-11-12', '2026-11-30')], [$missing, $this->received('2026-11-01', '2026-11-20', 500)], $this->payer());

        self::assertSame([PeriodAmounts::WARNING_MISSING_CZK_VAT], $november->warnings);
        self::assertSame(['PD-2026-7'], $november->missingCzk);
        self::assertSame([0, 500, -500], $this->totals($november));
        self::assertSame(['received-documents.received_vat'], $november->steps[1]->sources, 'Each source once.');
    }

    public function testAMonthlyTemplateCountsInEachFuturePeriod(): void
    {
        $periods = [$this->month('2026-11', '2026-11-12', '2026-11-30'), $this->month('2026-12', '2026-12-01', '2026-12-31'), $this->month('2027-01', '2027-01-01', '2027-01-31')];
        $issued = $this->issued('2026-11-20', 210_000);
        $received = $this->received('2026-12-01', '2026-12-03', 21_000);
        $december = $this->scheduled('2026-12-01', 210_000);
        $january = $this->scheduled('2027-01-01', 210_000);
        $second = $this->scheduled('2027-01-15', 100);

        [$nov, $dec, $jan] = VatCalculation::compute($periods, [$issued, $received], $this->payer(), [$december, $january, $second]);

        self::assertSame([210_000, 0, 210_000], $this->totals($nov));
        self::assertSame([], $nov->schedules);
        self::assertSame([210_000, 21_000, 189_000], $this->totals($dec));
        self::assertSame([0, 21_000, 210_000, 0], [$dec->documentsPart->output->minor(), $dec->documentsPart->input->minor(), $dec->schedulesPart->output->minor(), $dec->schedulesPart->input->minor()]);
        self::assertSame(['CZK', 'CZK'], [$dec->schedulesPart->output->currency()->code(), $dec->schedulesPart->input->currency()->code()]);
        self::assertSame([$december], $dec->schedules);
        self::assertSame([$received], $dec->documents);
        self::assertSame([
            [CalculationStep::OUTPUT, 210_000, VatCalculation::RULE_OUTPUT, ['invoicing.recurring_vat', 'received-documents.received_vat']],
            [CalculationStep::INPUT, 21_000, VatCalculation::RULE_INPUT, ['received-documents.received_vat']],
            [CalculationStep::RESULT, 189_000, VatCalculation::RULE_RESULT, ['output', 'input']],
        ], array_map(static fn (CalculationStep $step): array => [$step->name, $step->amount->minor(), $step->rule, $step->sources], $dec->steps));
        self::assertSame([210_100, 0, 210_100], $this->totals($jan));
        self::assertSame([$january, $second], $jan->schedules);
        self::assertSame([['invoicing.recurring_vat'], []], [$jan->steps[0]->sources, $jan->steps[1]->sources], 'A source is named once.');
        self::assertSame([[], []], [$jan->warnings, $jan->missingCzk]);
    }

    public function testAScheduleCountsOnlyWhenTheFirmIsAPayerOnItsTaxPoint(): void
    {
        $periods = [$this->month('2026-11', '2026-11-12', '2026-11-30')];

        [$period] = VatCalculation::compute($periods, [], $this->payer(), [$this->scheduled('2026-11-11', 500), $this->scheduled('2026-11-12', 100), $this->scheduled('2026-12-01', 900)]);

        self::assertSame([100, 0, 100], $this->totals($period), 'Neither the day before the payer day nor a day outside the periods counts.');
        self::assertCount(1, $period->schedules);
    }

    public function testAQuarterlyFilerCountsAScheduleInTheQuarterOfItsTaxPoint(): void
    {
        $stretches = [new VatStretch('q', VatRegistration::Registered, VatPeriodLength::Quarter, new \DateTimeImmutable('2026-11-12'))];

        [$q4, $q1] = VatCalculation::compute([$this->quarter('2026-Q4', '2026-11-12', '2026-12-31'), $this->quarter('2027-Q1', '2027-01-01', '2027-03-31')], [], $stretches, [$this->scheduled('2026-12-01', 100), $this->scheduled('2027-03-01', 200)]);

        self::assertSame([100, 200], [$q4->output->minor(), $q1->output->minor()]);
    }

    public function testAScheduleAtTodaysRateOrWithoutOneWarns(): void
    {
        $periods = [$this->month('2026-12', '2026-12-01', '2026-12-31'), $this->month('2027-01', '2027-01-01', '2027-01-31')];
        $document = new DocumentVat('invoicing.issued_vat', new VatSubject('invoice', 'i', null), '20260007', $this->day('2026-12-02'), $this->day('2026-12-02'), $this->czk(0), $this->czk(0), true);

        [$december, $january] = VatCalculation::compute($periods, [$document], $this->payer(), [
            new ScheduleAmount($this->scheduled('2026-12-01', 0)->schedule, $this->czk(0), true, true),
            new ScheduleAmount($this->scheduled('2026-12-01', 0)->schedule, $this->czk(52_500), true),
            new ScheduleAmount($this->scheduled('2027-01-01', 0)->schedule, $this->czk(52_500), true),
        ]);

        self::assertSame(['missing_czk_vat', 'fx_today'], $december->warnings);
        self::assertSame(['20260007', 'Hosting (2026-12-01)'], $december->missingCzk);
        self::assertSame([52_500, 0, 52_500], $this->totals($december));
        self::assertSame([['fx_today'], []], [$january->warnings, $january->missingCzk]);
    }

    /**
     * @return list<VatStretch>
     */
    private function payer(): array
    {
        return [
            new VatStretch('before', VatRegistration::NotRegistered, null, null),
            new VatStretch('payer', VatRegistration::Registered, VatPeriodLength::Month, new \DateTimeImmutable('2026-11-12', new \DateTimeZone('UTC'))),
        ];
    }

    /**
     * @return array{int, int, int}
     */
    private function totals(PeriodAmounts $amounts): array
    {
        return [$amounts->output->minor(), $amounts->input->minor(), $amounts->result->minor()];
    }

    private function issued(string $taxPoint, int $vat): DocumentVat
    {
        return new DocumentVat('invoicing.issued_vat', new VatSubject('invoice', 'i-'.$taxPoint, null), '2026'.$taxPoint, $this->day($taxPoint), $this->day($taxPoint), $this->czk($vat), $this->czk(0));
    }

    private function received(string $countsOn, string $taxPoint, int $vat): DocumentVat
    {
        return new DocumentVat('received-documents.received_vat', new VatSubject('received_document', 'r-'.$taxPoint, null), 'PD-'.$taxPoint, $this->day($countsOn), $this->day($taxPoint), $this->czk(0), $this->czk($vat));
    }

    private function month(string $key, string $start, string $end): VatPeriod
    {
        return new VatPeriod($key, $this->day($start), $this->day($end), VatPeriodLength::Month);
    }

    private function quarter(string $key, string $start, string $end): VatPeriod
    {
        return new VatPeriod($key, $this->day($start), $this->day($end), VatPeriodLength::Quarter);
    }

    private function day(string $day): \DateTimeImmutable
    {
        return new \DateTimeImmutable($day, new \DateTimeZone('Europe/Prague'));
    }

    private function scheduled(string $taxPoint, int $vat): ScheduleAmount
    {
        return new ScheduleAmount(new ScheduledVat('invoicing.recurring_vat', new VatSubject('recurring_invoice', 't-'.$taxPoint, null), 'Hosting ('.$taxPoint.')', $this->day($taxPoint), $this->czk($vat)), $this->czk($vat));
    }

    private function czk(int $minor): Money
    {
        return Money::ofMinor($minor, Currency::of('CZK'));
    }
}
