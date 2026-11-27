<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Application;

use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use Foresi\Catalog\Contract\BillingKind;
use Foresi\Deals\Domain\DealLine;
use Foresi\Shared\Domain\Money;

/**
 * The amounts of a billing plan, exact until they are rounded half-up to
 * minor units, as DealValues rounds the value of a deal.
 */
final class BillingAmounts
{
    /**
     * What one invoice of a recurring line comes to: its price × its quantity;
     * null for a line without a price or one that does not recur.
     */
    public static function ofLine(DealLine $line): ?Money
    {
        $price = $line->unitPrice();
        if (!$price instanceof Money || BillingKind::Recurring !== $line->billingKind()) {
            return null;
        }

        return Money::ofMinor(BigRational::of($price->minor())->multipliedBy($line->quantity() ?? '1')->toScale(0, RoundingMode::HalfUp)->toInt(), $price->currency());
    }

    /**
     * A share of a value, such as 50 % of the one-off value of a deal.
     *
     * @param string $percent a decimal number with up to two decimals, more than 0 and at most 100
     */
    public static function share(Money $value, string $percent): Money
    {
        return Money::ofMinor(BigRational::of($value->minor())->multipliedBy($percent)->dividedBy(100)->toScale(0, RoundingMode::HalfUp)->toInt(), $value->currency());
    }
}
