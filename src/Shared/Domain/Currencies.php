<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Domain;

use Brick\Money\CurrencyType;
use Brick\Money\IsoCurrencyProvider;

/**
 * The ISO 4217 currencies Foresi knows (ADR 0049).
 */
final class Currencies
{
    private function __construct()
    {
    }

    /**
     * The currencies in use today, ordered by code: what a picker offers.
     *
     * @return list<Currency>
     */
    public static function current(): array
    {
        return array_values(array_filter(self::all(), static fn (Currency $currency): bool => !$currency->isHistoric()));
    }

    /**
     * Every known currency, current and historic, ordered by code.
     *
     * @return list<Currency>
     */
    public static function all(): array
    {
        $all = [];
        foreach (IsoCurrencyProvider::getInstance()->getAvailableCurrencies() as $code => $iso) {
            if (CurrencyType::IsoCurrent === $iso->getCurrencyType() || CurrencyType::IsoHistorical === $iso->getCurrencyType()) {
                $all[] = Currency::of($code);
            }
        }

        return $all;
    }

    /**
     * Whether $code is a known ISO 4217 code, current or historic.
     */
    public static function isKnown(string $code): bool
    {
        return Currency::tryFrom($code) instanceof Currency;
    }

    /**
     * The current currencies in picker order: the home currency first, then
     * the ones the firm has used, then the rest by code.
     *
     * @param iterable<Currency> $used
     *
     * @return list<Currency>
     */
    public static function forPicker(?Currency $home, iterable $used = []): array
    {
        $ordered = [];
        if ($home instanceof Currency) {
            $ordered[$home->code()] = $home;
        }
        $rest = [];
        foreach ($used as $currency) {
            $rest[$currency->code()] = $currency;
        }
        ksort($rest);
        $ordered += $rest;
        foreach (self::current() as $currency) {
            $ordered += [$currency->code() => $currency];
        }

        return array_values($ordered);
    }

    /**
     * The options of a currency select: code => "EUR – Euro", in picker order.
     *
     * @param iterable<Currency> $used
     *
     * @return array<string, string>
     */
    public static function choices(?Currency $home = null, iterable $used = []): array
    {
        $choices = [];
        foreach (self::forPicker($home, $used) as $currency) {
            $choices[$currency->code()] = $currency->code().' – '.$currency->name();
        }

        return $choices;
    }

    /**
     * The codes of the current currencies.
     *
     * @return list<string>
     */
    public static function currentCodes(): array
    {
        return array_map(static fn (Currency $currency): string => $currency->code(), self::current());
    }
}
