<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Application;

use Foresi\CashFlow\Contract\FlowCategory;
use Foresi\CashFlow\Contract\PlannedVatMode;
use Foresi\CashFlow\Domain\MatchRule;
use Foresi\CashFlow\Domain\PlannedItem;
use Foresi\CashFlow\Domain\PlannedItemTerms;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Shared\Domain\Schedule\InvalidRecurrence;
use Foresi\Shared\Domain\Schedule\Recurrence;
use Foresi\Shared\Domain\Schedule\RecurrenceEnd;
use Foresi\Shared\Domain\Schedule\RecurrenceUnit;

/**
 * Checks a planned item as a client sent it and turns it into its terms, or
 * refuses the first field that cannot be used.
 */
final readonly class PlannedItemRules
{
    public const array CURRENCIES = ['CZK', 'EUR', 'USD'];
    public const array UNITS = ['day', 'week', 'month', 'year'];

    public function __construct(private CashFlowAccess $access)
    {
    }

    /**
     * @throws CashFlowRefused
     */
    public function terms(PlannedItemDraft $draft, \DateTimeZone $zone): PlannedItemTerms
    {
        $name = trim($draft->name);
        if ('' === $name || mb_strlen($name) > PlannedItem::MAX_NAME) {
            throw CashFlowRefused::invalid('name_required', 'name', \sprintf('A planned item has a name of 1 to %d characters.', PlannedItem::MAX_NAME));
        }
        $category = FlowCategory::tryFrom($draft->category) ?? throw CashFlowRefused::invalid('category_unknown', 'category', 'The category is one of '.implode(', ', FlowCategory::values()).'.');
        $currency = Currency::tryFrom($draft->currency) ?? throw CashFlowRefused::invalid('currency_unknown', 'currency', 'The currency is one of '.implode(', ', self::CURRENCIES).'.');
        $amount = self::money($draft->amount, $currency, 'amount');
        if ($amount->minor() <= 0) {
            throw CashFlowRefused::invalid('amount_not_positive', 'amount', 'The amount of a planned item is more than zero; the direction says whether it comes in or goes out.');
        }
        $on = self::day($draft->on, $zone, 'on');

        return new PlannedItemTerms(
            $name,
            $category,
            $amount,
            $on,
            $this->recurrence($draft, $on, $zone),
            null === $draft->counterpartyId ? null : $this->access->counterparty($draft->counterpartyId),
            $draft->included,
            $this->matchRule($draft, $currency),
            ...$this->vat($draft),
        );
    }

    /**
     * The VAT mode and the rate with two decimals: none takes no rate, and
     * included or excluded a rate from 0 to 100 percent.
     *
     * @return array{PlannedVatMode, ?string}
     *
     * @throws CashFlowRefused
     */
    private function vat(PlannedItemDraft $draft): array
    {
        $mode = PlannedVatMode::tryFrom($draft->vatMode) ?? throw CashFlowRefused::invalid('vat_mode_unknown', 'vatMode', 'vat_mode is one of '.implode(', ', PlannedVatMode::values()).'.');
        $rate = $this->blankToNull($draft->vatRate);
        if (PlannedVatMode::None === $mode) {
            if (null !== $rate) {
                throw CashFlowRefused::invalid('vat_rate_without_mode', 'vatRate', 'A VAT rate needs vat_mode included or excluded.');
            }

            return [$mode, null];
        }
        if (null === $rate) {
            throw CashFlowRefused::invalid('vat_rate_required', 'vatRate', 'A planned item with VAT included or excluded states its rate, such as "21".');
        }
        // 0 to 100 with at most two decimals, written with leading zeros or without.
        if (1 !== preg_match('/^0*(?:100(?:\.0{1,2})?|[0-9]{1,2}(?:\.[0-9]{1,2})?)\z/', $rate)) {
            throw CashFlowRefused::invalid('vat_rate_invalid', 'vatRate', 'A VAT rate is a percent from 0 to 100 with at most two decimals, such as "21" or "12.5".');
        }

        return [$mode, number_format((float) $rate, 2, '.', '')];
    }

    /**
     * A calendar day in ISO 8601 at midnight in $zone.
     *
     * @throws CashFlowRefused invalid date_invalid
     */
    public static function day(string $value, \DateTimeZone $zone, string $field): \DateTimeImmutable
    {
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $zone);
        if (false === $day || $day->format('Y-m-d') !== $value) {
            throw CashFlowRefused::invalid('date_invalid', $field, 'A day is written in ISO 8601, such as 2026-12-31.');
        }

        return $day;
    }

    /**
     * @throws CashFlowRefused invalid amount_invalid
     */
    public static function money(string $value, Currency $currency, string $field): Money
    {
        if (1 !== preg_match('/^-?[0-9]{1,13}(\.[0-9]{1,2})?\z/', trim($value))) {
            throw CashFlowRefused::invalid('amount_invalid', $field, 'An amount is a decimal number with at most two decimals, such as "1500.00".');
        }

        return Money::of(trim($value), $currency);
    }

    /**
     * @throws CashFlowRefused
     */
    private function recurrence(PlannedItemDraft $draft, \DateTimeImmutable $on, \DateTimeZone $zone): ?Recurrence
    {
        if (null === $draft->repeat) {
            if (null !== $draft->endsOn || null !== $draft->endsAfter || $draft->lastDayOfMonth) {
                throw CashFlowRefused::invalid('not_recurring', 'repeat', 'An end, a count or the last day of the month needs repeat; a one-off item falls once on its day.');
            }

            return null;
        }
        $unit = RecurrenceUnit::tryFrom($draft->repeat) ?? throw CashFlowRefused::invalid('repeat_unknown', 'repeat', 'repeat is one of '.implode(', ', self::UNITS).'.');
        if (null !== $draft->endsOn && null !== $draft->endsAfter) {
            throw CashFlowRefused::invalid('end_ambiguous', 'endsAfter', 'A planned item ends on a day or after a count, not both.');
        }
        $endsOn = null === $draft->endsOn ? null : self::day($draft->endsOn, $zone, 'endsOn');
        $end = match (true) {
            $endsOn instanceof \DateTimeImmutable => RecurrenceEnd::OnDate,
            null !== $draft->endsAfter => RecurrenceEnd::AfterCount,
            default => RecurrenceEnd::Never,
        };
        try {
            return new Recurrence($on, $unit, $draft->every, $draft->lastDayOfMonth, $end, $endsOn, $draft->endsAfter);
        } catch (InvalidRecurrence $invalid) {
            throw CashFlowRefused::invalid('recurrence_invalid', 'repeat', $invalid->getMessage());
        }
    }

    /**
     * @throws CashFlowRefused
     */
    private function matchRule(PlannedItemDraft $draft, Currency $currency): MatchRule
    {
        $account = $this->blankToNull($draft->matchAccount);
        if (null !== $account && mb_strlen($account) > PlannedItem::MAX_MATCH_ACCOUNT) {
            throw CashFlowRefused::invalid('match_account_invalid', 'matchAccount', \sprintf('A bank account has at most %d characters.', PlannedItem::MAX_MATCH_ACCOUNT));
        }
        $symbol = $this->blankToNull($draft->matchVariableSymbol);
        if (null !== $symbol && 1 !== preg_match('/^[0-9]{1,10}\z/', $symbol)) {
            throw CashFlowRefused::invalid('variable_symbol_invalid', 'matchVariableSymbol', 'A variable symbol is 1 to 10 digits.');
        }
        $tolerance = null === $draft->matchTolerance ? null : self::money($draft->matchTolerance, $currency, 'matchTolerance')->minor();
        if (null !== $tolerance && $tolerance < 0) {
            throw CashFlowRefused::invalid('tolerance_negative', 'matchTolerance', 'A tolerance is zero or more.');
        }

        return MatchRule::of($account, $symbol, $tolerance);
    }

    private function blankToNull(?string $value): ?string
    {
        $value = null === $value ? null : trim($value);

        return '' === $value ? null : $value;
    }
}
