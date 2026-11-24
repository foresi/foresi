<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Catalog\Application;

use Foresi\Catalog\Contract\BillingKind;
use Foresi\Catalog\Domain\Product;
use Foresi\Catalog\Domain\ProductTerms;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Shared\Domain\Schedule\RecurrenceUnit;

/**
 * What a product may say. A recurring product bills every interval (1 to 99)
 * of a unit of time, a number of cycles (1 to 999) or with no end; a one-off
 * product has none of these. The price per unit is optional, zero or more,
 * with at most two decimals. The name is required; the unit may be empty. A
 * time-based product is billed by the hour: its unit is "h" (an empty unit is
 * taken as "h"), its price is the hourly rate and is required, and it has no
 * recurrence.
 */
final readonly class ProductRules
{
    public const array BILLING_KINDS = ['one_off', 'recurring', 'time_based'];
    public const string HOUR = 'h';
    public const array UNITS = ['day', 'week', 'month', 'year'];
    public const array CURRENCIES = ['CZK', 'EUR', 'USD'];

    /**
     * @throws CatalogRefused invalid
     */
    public function terms(ProductDraft $draft): ProductTerms
    {
        $name = trim($draft->name);
        if ('' === $name) {
            throw CatalogRefused::invalid('name_required', 'name', 'A product needs a name.');
        }
        if (mb_strlen($name) > Product::MAX_NAME) {
            throw CatalogRefused::invalid('name_too_long', 'name', \sprintf('The name is at most %d characters.', Product::MAX_NAME));
        }
        $unit = trim($draft->unit);
        if (mb_strlen($unit) > Product::MAX_UNIT) {
            throw CatalogRefused::invalid('unit_too_long', 'unit', \sprintf('The unit is at most %d characters, as on an invoice line.', Product::MAX_UNIT));
        }
        $description = trim($draft->description);
        if (mb_strlen($description) > Product::MAX_DESCRIPTION) {
            throw CatalogRefused::invalid('description_too_long', 'description', \sprintf('The description is at most %d characters.', Product::MAX_DESCRIPTION));
        }
        $kind = BillingKind::tryFrom($draft->billingKind) ?? throw CatalogRefused::invalid('billing_kind_unknown', 'billingKind', 'The billing kind is one of '.implode(', ', self::BILLING_KINDS).'.');
        $price = $this->price($draft);
        if (BillingKind::TimeBased === $kind) {
            $unit = $this->hourly($unit, $price);
        }

        return new ProductTerms($name, $description, $unit, $price, $kind, ...$this->recurrence($kind, $draft));
    }

    /**
     * The unit of a time-based product: the hour, which an empty unit means.
     *
     * @throws CatalogRefused invalid
     */
    private function hourly(string $unit, ?Money $rate): string
    {
        if ('' !== $unit && self::HOUR !== $unit) {
            throw CatalogRefused::invalid('time_based_unit', 'unit', 'A time-based product is billed by the hour: its unit is h.');
        }
        if (!$rate instanceof Money) {
            throw CatalogRefused::invalid('rate_required', 'unitPrice', 'A time-based product needs its hourly rate: give the unit price.');
        }

        return self::HOUR;
    }

    /**
     * @throws CatalogRefused invalid
     */
    private function price(ProductDraft $draft): ?Money
    {
        if (null === $draft->unitPrice) {
            return null;
        }
        $currency = Currency::tryFrom($draft->currency) ?? throw CatalogRefused::invalid('currency_unknown', 'currency', 'The currency is one of '.implode(', ', self::CURRENCIES).'.');
        $value = trim($draft->unitPrice);
        if (1 !== preg_match('/^-?[0-9]{1,13}(\.[0-9]{1,2})?\z/', $value)) {
            throw CatalogRefused::invalid('unit_price_invalid', 'unitPrice', 'A price is a decimal number with at most two decimals, such as "1500.00".');
        }
        $price = Money::of($value, $currency);
        if ($price->isNegative()) {
            throw CatalogRefused::invalid('unit_price_negative', 'unitPrice', 'A price is zero or more.');
        }

        return $price;
    }

    /**
     * @return array{?RecurrenceUnit, ?int, ?int}
     *
     * @throws CatalogRefused invalid
     */
    private function recurrence(BillingKind $kind, ProductDraft $draft): array
    {
        if (BillingKind::OneOff === $kind) {
            foreach (['recurrenceUnit' => $draft->recurrenceUnit, 'interval' => $draft->interval, 'cycles' => $draft->cycles] as $field => $value) {
                if (null !== $value) {
                    throw CatalogRefused::invalid('one_off_recurrence', $field, 'A one-off product has no recurrence unit, interval or cycles; make it recurring to bill it on a period.');
                }
            }

            return [null, null, null];
        }
        if (BillingKind::TimeBased === $kind) {
            foreach (['recurrenceUnit' => $draft->recurrenceUnit, 'interval' => $draft->interval, 'cycles' => $draft->cycles] as $field => $value) {
                if (null !== $value) {
                    throw CatalogRefused::invalid('time_based_recurrence', $field, 'A time-based product has no recurrence unit, interval or cycles; it is billed by the hours worked.');
                }
            }

            return [null, null, null];
        }
        if (null === $draft->recurrenceUnit) {
            throw CatalogRefused::invalid('recurrence_unit_required', 'recurrenceUnit', 'A recurring product bills every day, week, month or year: give the recurrence unit.');
        }
        $unit = RecurrenceUnit::tryFrom($draft->recurrenceUnit) ?? throw CatalogRefused::invalid('recurrence_unit_unknown', 'recurrenceUnit', 'The recurrence unit is one of '.implode(', ', self::UNITS).'.');
        if (null === $draft->interval) {
            throw CatalogRefused::invalid('interval_required', 'interval', 'A recurring product bills every interval of its unit: give the interval, 1 for every month.');
        }
        if ($draft->interval < 1 || $draft->interval > Product::MAX_INTERVAL) {
            throw CatalogRefused::invalid('interval_invalid', 'interval', \sprintf('The interval is a whole number from 1 to %d.', Product::MAX_INTERVAL));
        }
        if (null !== $draft->cycles && ($draft->cycles < 1 || $draft->cycles > Product::MAX_CYCLES)) {
            throw CatalogRefused::invalid('cycles_invalid', 'cycles', \sprintf('The cycles are a whole number from 1 to %d, or null for no end.', Product::MAX_CYCLES));
        }

        return [$unit, $draft->interval, $draft->cycles];
    }
}
