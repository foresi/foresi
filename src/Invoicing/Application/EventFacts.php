<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Contract\Event\DocumentKindValue;
use App\Invoicing\Contract\Event\PaymentDirection as PaymentDirectionValue;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\PaymentDirection;
use App\Shared\Domain\Money;

/**
 * How the events of this module write what they state: amounts as decimal strings
 * of the currency, dates as ISO, and the contract enums mapped from the domain's.
 */
final class EventFacts
{
    public static function amount(Money $money): string
    {
        $unit = 10 ** $money->currency()->minorUnits();
        $minor = abs($money->minor());

        return \sprintf('%s%d.%0'.$money->currency()->minorUnits().'d', $money->isNegative() ? '-' : '', intdiv($minor, $unit), $minor % $unit);
    }

    public static function day(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d');
    }

    public static function kind(DocumentKind $kind): DocumentKindValue
    {
        return DocumentKindValue::from($kind->value);
    }

    public static function direction(PaymentDirection $direction): PaymentDirectionValue
    {
        return PaymentDirectionValue::from($direction->value);
    }
}
