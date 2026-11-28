<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Deals\Application;

use Foresi\Deals\Application\DealLineDraft;
use Foresi\Deals\Application\DealLineRules;
use Foresi\Deals\Application\SalesRefused;
use Foresi\Deals\Domain\DealLine;
use Foresi\Shared\Domain\Schedule\RecurrenceUnit;
use PHPUnit\Framework\TestCase;

/**
 * The edges of a line as it is sent: text is trimmed and its length counts
 * characters, the limits themselves are allowed, and each refusal names its
 * field and says what is accepted.
 */
final class DealLineRulesTest extends TestCase
{
    private DealLineRules $rules;

    protected function setUp(): void
    {
        $this->rules = new DealLineRules();
    }

    public function testTextIsTrimmedAndCountsCharacters(): void
    {
        $terms = $this->rules->terms($this->draft(name: '  '.str_repeat('č', DealLine::MAX_NAME).' ', unit: ' '.str_repeat('č', DealLine::MAX_UNIT).'  ', quantity: ' 1.5 ', unitPrice: ' 1500.50 '));

        self::assertSame([str_repeat('č', DealLine::MAX_NAME), str_repeat('č', DealLine::MAX_UNIT), '1.500'], [$terms->name, $terms->unit, $terms->quantity]);
        self::assertSame(150050, $terms->unitPrice?->minor());
        self::assertSame('2.000', $this->rules->quantity(' 2 '));

        $this->assertRefused('name', 'name_too_long', 'The name is at most 200 characters.', $this->draft(name: str_repeat('č', DealLine::MAX_NAME + 1)));
        $this->assertRefused('unit', 'unit_too_long', 'The unit is at most 32 characters, as on an invoice line.', $this->draft(unit: str_repeat('č', DealLine::MAX_UNIT + 1)));
    }

    public function testTheRefusalsOfACurrencyAndARecurrenceUnitListWhatIsAccepted(): void
    {
        $this->assertRefused('currency', 'currency_unknown', 'The currency is not an ISO 4217 code; GET /api/v1/currencies lists them.', $this->draft(currency: 'XYZ'));
        $this->assertRefused('recurrenceUnit', 'recurrence_unit_unknown', 'The recurrence unit is one of day, week, month, year.', $this->draft(billingKind: 'recurring', recurrenceUnit: 'hour', interval: 1));
    }

    public function testAOneOffLineRefusesEachPartOfARecurrence(): void
    {
        $this->assertRefused('recurrenceUnit', 'one_off_recurrence', null, $this->draft(recurrenceUnit: 'month'));
        $this->assertRefused('interval', 'one_off_recurrence', null, $this->draft(interval: 1));
        $this->assertRefused('cycles', 'one_off_recurrence', null, $this->draft(cycles: 3));
    }

    public function testTheIntervalAndTheCyclesAllowTheirLimits(): void
    {
        foreach ([[1, 1], [DealLine::MAX_INTERVAL, DealLine::MAX_CYCLES], [1, null]] as [$interval, $cycles]) {
            $terms = $this->rules->terms($this->draft(billingKind: 'recurring', recurrenceUnit: 'month', interval: $interval, cycles: $cycles));
            self::assertSame([RecurrenceUnit::Month, $interval, $cycles], [$terms->recurrenceUnit, $terms->interval, $terms->cycles]);
        }

        foreach ([0, DealLine::MAX_INTERVAL + 1] as $interval) {
            $this->assertRefused('interval', 'interval_invalid', 'The interval is a whole number from 1 to 99.', $this->draft(billingKind: 'recurring', recurrenceUnit: 'month', interval: $interval));
        }
        foreach ([0, DealLine::MAX_CYCLES + 1] as $cycles) {
            $this->assertRefused('cycles', 'cycles_invalid', 'The cycles are a whole number from 1 to 999, or null for no end.', $this->draft(billingKind: 'recurring', recurrenceUnit: 'month', interval: 1, cycles: $cycles));
        }
    }

    public function testATimeBasedLineIsBilledByTheHourAtAnHourlyRate(): void
    {
        $terms = $this->rules->terms($this->draft(unit: '', quantity: '40', unitPrice: '1200.00', billingKind: 'time_based'));
        self::assertSame(['h', '40.000', 120000], [$terms->unit, $terms->quantity, $terms->unitPrice?->minor()]);
        self::assertSame('h', $this->rules->terms($this->draft(unit: 'h', quantity: '1', billingKind: 'time_based'))->unit);

        $this->assertRefused('unit', 'time_based_unit', 'A time-based line is billed by the hour: its unit is h.', $this->draft(unit: 'day', billingKind: 'time_based'));
        $this->assertRefused('unitPrice', 'rate_required', 'A time-based line needs its hourly rate: give the unit price.', $this->draft(unit: 'h', unitPrice: null, billingKind: 'time_based'));
        $this->assertRefused('recurrenceUnit', 'time_based_recurrence', null, $this->draft(unit: 'h', billingKind: 'time_based', recurrenceUnit: 'month'));
        $this->assertRefused('interval', 'time_based_recurrence', null, $this->draft(unit: 'h', billingKind: 'time_based', interval: 1));
        $this->assertRefused('cycles', 'time_based_recurrence', null, $this->draft(unit: 'h', billingKind: 'time_based', cycles: 2));
    }

    public function testOnlyATimeBasedLineMayHaveNoQuantity(): void
    {
        $terms = $this->rules->terms($this->draft(unit: 'h', quantity: null, billingKind: 'time_based'));
        self::assertNull($terms->quantity);
        self::assertNull($this->rules->terms($this->draft(unit: 'h', quantity: ' ', billingKind: 'time_based'))->quantity);

        $this->assertRefused('quantity', 'quantity_required', 'A quantity is needed; only a time-based line may leave it out.', $this->draft(quantity: null));
        $this->assertRefused('quantity', 'quantity_required', null, $this->draft(quantity: '', billingKind: 'recurring', recurrenceUnit: 'month', interval: 1));
    }

    public function testTheKindRefusalListsAllThreeKinds(): void
    {
        $this->assertRefused('billingKind', 'billing_kind_unknown', 'The billing kind is one of one_off, recurring, time_based.', $this->draft(billingKind: 'weekly'));
    }

    private function draft(
        string $name = 'Roof audit',
        string $unit = 'piece',
        ?string $quantity = '1',
        ?string $unitPrice = '1000.00',
        string $currency = 'CZK',
        string $billingKind = 'one_off',
        ?string $recurrenceUnit = null,
        ?int $interval = null,
        ?int $cycles = null,
    ): DealLineDraft {
        return new DealLineDraft($name, $unit, $quantity, $unitPrice, $currency, $billingKind, $recurrenceUnit, $interval, $cycles);
    }

    private function assertRefused(string $field, string $reason, ?string $message, DealLineDraft $draft): void
    {
        try {
            $this->rules->terms($draft);
            self::fail(\sprintf('The %s was accepted.', $field));
        } catch (SalesRefused $refused) {
            self::assertSame([$field, $reason], [$refused->field(), $refused->reason()]);
            if (null !== $message) {
                self::assertSame($message, $refused->getMessage());
            }
        }
    }
}
