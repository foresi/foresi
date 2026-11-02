<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Invoicing\Application\LineInput;
use App\Invoicing\Application\PreviewRow;
use App\Invoicing\Application\RecurringInput;
use App\Invoicing\Application\RecurringPreview;
use App\Invoicing\Domain\Percent;
use App\Invoicing\Domain\PriceType;
use App\Invoicing\Domain\Quantity;
use App\Invoicing\Domain\Recurrence;
use App\Invoicing\Domain\RecurrenceEnd;
use App\Invoicing\Domain\RecurrenceUnit;
use App\Invoicing\Domain\RecurringDelivery;
use App\Invoicing\Domain\TaxPointRule;
use App\Invoicing\Domain\TaxTreatment;
use App\Invoicing\Domain\VatRateKind;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\PaymentMethod;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use PHPUnit\Framework\TestCase;

final class RecurringPreviewTest extends TestCase
{
    public function testThePreviewShowsFiveDatesForMonthlyOn31st(): void
    {
        $rows = new RecurringPreview()->next($this->input($this->recurrence('2027-01-31')), 0, $this->day('2026-11-02'));

        self::assertSame(['2027-01-31', '2027-02-28', '2027-03-31', '2027-04-30', '2027-05-31'], $this->dates($rows, 'issue'));
        self::assertSame(['2027-01-31', '2027-02-28', '2027-03-31', '2027-04-30', '2027-05-31'], $this->dates($rows, 'taxPoint'));
        self::assertSame(['2027-02-14', '2027-03-14', '2027-04-14', '2027-05-14', '2027-06-14'], $this->dates($rows, 'due'));
    }

    public function testThePreviewStopsAtTheEndRule(): void
    {
        $preview = new RecurringPreview();

        $afterCount = $preview->next($this->input($this->recurrence('2026-12-01', RecurrenceEnd::AfterCount, endAfter: 3)), 0, $this->day('2026-11-02'));
        $onDate = $preview->next($this->input($this->recurrence('2026-12-01', RecurrenceEnd::OnDate, endOn: '2027-02-01')), 0, $this->day('2026-11-02'));
        $countedSoFar = $preview->next($this->input($this->recurrence('2026-12-01', RecurrenceEnd::AfterCount, endAfter: 3)), 2, $this->day('2026-11-02'));

        self::assertSame(['2026-12-01', '2027-01-01', '2027-02-01'], $this->dates($afterCount, 'issue'));
        self::assertSame(['2026-12-01', '2027-01-01', '2027-02-01'], $this->dates($onDate, 'issue'), 'the end date is included');
        self::assertSame(['2026-12-01'], $this->dates($countedSoFar, 'issue'), 'issued invoices count towards the end, not the dates passed');
    }

    public function testThePreviewResolvesVariables(): void
    {
        $rows = new RecurringPreview()->next($this->input($this->recurrence('2026-12-01'), 'Hosting {month/year}, Q{quarter} {year}'), 0, $this->day('2026-11-02'), 2);

        self::assertSame(['Hosting 12/2026, Q4 2026', 'Hosting 1/2027, Q1 2027'], array_map(static fn (PreviewRow $row): string => $row->description, $rows));
    }

    public function testATaxPointOnTheLastDayOfTheMonth(): void
    {
        $rows = new RecurringPreview()->next($this->input($this->recurrence('2026-12-01'), taxPoint: TaxPointRule::LastDayOfMonth, dueDays: 0), 0, $this->day('2026-11-02'), 2);

        self::assertSame(['2026-12-31', '2027-01-31'], $this->dates($rows, 'taxPoint'));
        self::assertSame(['2026-12-01', '2027-01-01'], $this->dates($rows, 'due'));
    }

    public function testAnEditPreviewsFromToday(): void
    {
        $rows = new RecurringPreview()->next($this->input($this->recurrence('2026-06-16', unit: RecurrenceUnit::Week, every: 2)), 10, $this->day('2026-11-02'), 3);

        self::assertSame(['2026-11-03', '2026-11-17', '2026-12-01'], $this->dates($rows, 'issue'));
    }

    public function testTheLastDayOfTheMonthAndACount(): void
    {
        $recurrence = new Recurrence($this->day('2027-02-10'), RecurrenceUnit::Month, 1, true, RecurrenceEnd::Never, null, null);

        $rows = new RecurringPreview()->next($this->input($recurrence), 0, $this->day('2026-11-02'), 3);

        self::assertSame(['2027-02-28', '2027-03-31', '2027-04-30'], $this->dates($rows, 'issue'));
    }

    private function recurrence(string $first, RecurrenceEnd $end = RecurrenceEnd::Never, ?string $endOn = null, ?int $endAfter = null, RecurrenceUnit $unit = RecurrenceUnit::Month, int $every = 1): Recurrence
    {
        return new Recurrence($this->day($first), $unit, $every, false, $end, null === $endOn ? null : $this->day($endOn), $endAfter);
    }

    private function input(Recurrence $recurrence, string $description = 'Hosting', TaxPointRule $taxPoint = TaxPointRule::IssueDate, int $dueDays = 14): RecurringInput
    {
        $line = new LineInput('Hosting', Quantity::fromString('1'), 'ks', Money::of('100.00', Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0'));

        return new RecurringInput('firm', null, 'customer', PaymentMethod::BankTransfer, null, '', '', $description, '', '', DocumentLanguage::Czech, [$line], $recurrence, $taxPoint, $dueDays, RecurringDelivery::Send);
    }

    private function day(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date, new \DateTimeZone('Europe/Prague'));
    }

    /**
     * @param list<PreviewRow> $rows
     *
     * @return list<string>
     */
    private function dates(array $rows, string $field): array
    {
        return array_map(static fn (PreviewRow $row): string => (match ($field) {
            'issue' => $row->issue,
            'taxPoint' => $row->taxPoint,
            default => $row->due,
        })->format('Y-m-d'), $rows);
    }
}
