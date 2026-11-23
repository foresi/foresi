<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Application;

use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use Foresi\Catalog\Contract\BillingKind;
use Foresi\Sales\Domain\DealLine;
use Foresi\Shared\Domain\Money;
use Foresi\Shared\Domain\Schedule\RecurrenceUnit;

/**
 * Works out the value of a deal from its lines (P7 of plan 32). Each priced
 * line is its price per unit × its quantity × the factor that puts it on a
 * month's terms, exact until it is rounded half-up to minor units, once per
 * line, and then summed per currency. A one-off line counts once; a recurring
 * one every interval of a month is divided by the interval, of a year by
 * twelve intervals; a week is 52/12 and a day 365/12 of a month, which makes
 * the value approximate. A line without a price adds nothing.
 */
final class DealValues
{
    /**
     * @param list<DealLine> $lines
     */
    public static function of(array $lines): DealValue
    {
        $oneOff = [];
        $monthly = [];
        $approximate = false;
        foreach ($lines as $line) {
            $price = $line->unitPrice();
            if (!$price instanceof Money) {
                continue;
            }
            $exact = BigRational::of($price->minor())->multipliedBy($line->quantity());
            if (BillingKind::OneOff === $line->billingKind()) {
                $oneOff = self::added($oneOff, Money::ofMinor(self::rounded($exact), $price->currency()));

                continue;
            }
            [$factor, $approximation] = self::monthlyFactor($line->recurrenceUnit() ?? RecurrenceUnit::Month, $line->interval() ?? 1);
            $approximate = $approximate || $approximation;
            $monthly = self::added($monthly, Money::ofMinor(self::rounded($exact->multipliedBy($factor)), $price->currency()));
        }
        ksort($oneOff);
        ksort($monthly);

        return new DealValue($oneOff, $monthly, $approximate);
    }

    /**
     * @return array{BigRational, bool} how much of it falls in a month, and whether that is approximate
     */
    private static function monthlyFactor(RecurrenceUnit $unit, int $interval): array
    {
        return match ($unit) {
            RecurrenceUnit::Month => [BigRational::ofFraction(1, $interval), false],
            RecurrenceUnit::Year => [BigRational::ofFraction(1, 12 * $interval), false],
            RecurrenceUnit::Week => [BigRational::ofFraction(52, 12 * $interval), true],
            RecurrenceUnit::Day => [BigRational::ofFraction(365, 12 * $interval), true],
        };
    }

    private static function rounded(BigRational $minor): int
    {
        return $minor->toScale(0, RoundingMode::HalfUp)->toInt();
    }

    /**
     * @param array<string, Money> $totals
     *
     * @return array<string, Money>
     */
    private static function added(array $totals, Money $amount): array
    {
        $code = $amount->currency()->value;
        $totals[$code] = isset($totals[$code]) ? $totals[$code]->plus($amount) : $amount;

        return $totals;
    }
}
