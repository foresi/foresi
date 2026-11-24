<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Application;

use Brick\Math\BigDecimal;
use Foresi\Catalog\Contract\BillingKind;
use Foresi\Sales\Domain\DealLine;
use Foresi\Sales\Domain\DealLineTerms;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Shared\Domain\Schedule\RecurrenceUnit;

/**
 * What a line of a deal may say, by the rules the catalog has for a product:
 * a recurring line bills every interval (1 to 99) of a unit of time, a number
 * of cycles (1 to 999) or with no end; a one-off line has none of these. The
 * price per unit is optional, zero or more, with at most two decimals. The
 * quantity is more than zero, with at most three decimals. A time-based line
 * is billed by the hour at the rate of its price (needed), has no recurrence
 * and may have no quantity: the hours expected, not yet estimated.
 */
final readonly class DealLineRules
{
    public const array BILLING_KINDS = ['one_off', 'recurring', 'time_based'];
    public const string HOUR = 'h';
    public const array UNITS = ['day', 'week', 'month', 'year'];
    public const array CURRENCIES = ['CZK', 'EUR', 'USD'];

    /**
     * @throws SalesRefused invalid
     */
    public function terms(DealLineDraft $draft): DealLineTerms
    {
        $name = trim($draft->name);
        if ('' === $name) {
            throw SalesRefused::invalid('name_required', 'name', 'A line needs a name.');
        }
        if (mb_strlen($name) > DealLine::MAX_NAME) {
            throw SalesRefused::invalid('name_too_long', 'name', \sprintf('The name is at most %d characters.', DealLine::MAX_NAME));
        }
        $unit = trim($draft->unit);
        if (mb_strlen($unit) > DealLine::MAX_UNIT) {
            throw SalesRefused::invalid('unit_too_long', 'unit', \sprintf('The unit is at most %d characters, as on an invoice line.', DealLine::MAX_UNIT));
        }
        $kind = BillingKind::tryFrom($draft->billingKind) ?? throw SalesRefused::invalid('billing_kind_unknown', 'billingKind', 'The billing kind is one of '.implode(', ', self::BILLING_KINDS).'.');

        $price = $this->price($draft);
        if (BillingKind::TimeBased === $kind) {
            $unit = $this->hourly($unit, $price);
        }

        return new DealLineTerms($name, $unit, $this->quantityOf($kind, $draft->quantity), $price, $kind, ...$this->recurrence($kind, $draft));
    }

    /**
     * The quantity of a line of this kind: a time-based line may leave it out
     * (null, not estimated), every other kind needs one.
     *
     * @throws SalesRefused invalid
     */
    public function quantityOf(BillingKind $kind, ?string $quantity): ?string
    {
        if (null === $quantity || '' === trim($quantity)) {
            if (BillingKind::TimeBased === $kind) {
                return null;
            }

            throw SalesRefused::invalid('quantity_required', 'quantity', 'A quantity is needed; only a time-based line may leave it out.');
        }

        return $this->quantity($quantity);
    }

    /**
     * The unit of a time-based line: the hour, which an empty unit means.
     *
     * @throws SalesRefused invalid
     */
    private function hourly(string $unit, ?Money $rate): string
    {
        if ('' !== $unit && self::HOUR !== $unit) {
            throw SalesRefused::invalid('time_based_unit', 'unit', 'A time-based line is billed by the hour: its unit is h.');
        }
        if (!$rate instanceof Money) {
            throw SalesRefused::invalid('rate_required', 'unitPrice', 'A time-based line needs its hourly rate: give the unit price.');
        }

        return self::HOUR;
    }

    /**
     * A quantity as a decimal with three places, such as "1.500".
     *
     * @throws SalesRefused invalid
     */
    public function quantity(string $quantity): string
    {
        $value = trim($quantity);
        if (1 !== preg_match('/^[0-9]{1,12}(\.[0-9]{1,3})?\z/', $value)) {
            throw SalesRefused::invalid('quantity_invalid', 'quantity', 'A quantity is a decimal number with at most three decimals, such as "1.5".');
        }
        $decimal = BigDecimal::of($value)->toScale(DealLine::QUANTITY_SCALE);
        if (!$decimal->isPositive()) {
            throw SalesRefused::invalid('quantity_not_positive', 'quantity', 'A quantity is more than zero.');
        }

        return (string) $decimal;
    }

    /**
     * @throws SalesRefused invalid
     */
    private function price(DealLineDraft $draft): ?Money
    {
        if (null === $draft->unitPrice) {
            return null;
        }
        $currency = Currency::tryFrom($draft->currency) ?? throw SalesRefused::invalid('currency_unknown', 'currency', 'The currency is one of '.implode(', ', self::CURRENCIES).'.');
        $value = trim($draft->unitPrice);
        if (1 !== preg_match('/^[0-9]{1,13}(\.[0-9]{1,2})?\z/', $value)) {
            throw SalesRefused::invalid('unit_price_invalid', 'unitPrice', 'A price is zero or more, a decimal number with at most two decimals, such as "1500.00".');
        }

        return Money::of($value, $currency);
    }

    /**
     * @return array{?RecurrenceUnit, ?int, ?int}
     *
     * @throws SalesRefused invalid
     */
    private function recurrence(BillingKind $kind, DealLineDraft $draft): array
    {
        if (BillingKind::OneOff === $kind) {
            foreach (['recurrenceUnit' => $draft->recurrenceUnit, 'interval' => $draft->interval, 'cycles' => $draft->cycles] as $field => $value) {
                if (null !== $value) {
                    throw SalesRefused::invalid('one_off_recurrence', $field, 'A one-off line has no recurrence unit, interval or cycles; make it recurring to bill it on a period.');
                }
            }

            return [null, null, null];
        }
        if (BillingKind::TimeBased === $kind) {
            foreach (['recurrenceUnit' => $draft->recurrenceUnit, 'interval' => $draft->interval, 'cycles' => $draft->cycles] as $field => $value) {
                if (null !== $value) {
                    throw SalesRefused::invalid('time_based_recurrence', $field, 'A time-based line has no recurrence unit, interval or cycles; it is billed by the hours worked.');
                }
            }

            return [null, null, null];
        }
        if (null === $draft->recurrenceUnit) {
            throw SalesRefused::invalid('recurrence_unit_required', 'recurrenceUnit', 'A recurring line bills every day, week, month or year: give the recurrence unit.');
        }
        $unit = RecurrenceUnit::tryFrom($draft->recurrenceUnit) ?? throw SalesRefused::invalid('recurrence_unit_unknown', 'recurrenceUnit', 'The recurrence unit is one of '.implode(', ', self::UNITS).'.');
        $interval = $draft->interval ?? throw SalesRefused::invalid('interval_required', 'interval', 'A recurring line bills every interval of its unit: give the interval, 1 for every month.');
        if ($interval < 1 || $interval > DealLine::MAX_INTERVAL) {
            throw SalesRefused::invalid('interval_invalid', 'interval', \sprintf('The interval is a whole number from 1 to %d.', DealLine::MAX_INTERVAL));
        }
        if (null !== $draft->cycles && ($draft->cycles < 1 || $draft->cycles > DealLine::MAX_CYCLES)) {
            throw SalesRefused::invalid('cycles_invalid', 'cycles', \sprintf('The cycles are a whole number from 1 to %d, or null for no end.', DealLine::MAX_CYCLES));
        }

        return [$unit, $interval, $draft->cycles];
    }
}
