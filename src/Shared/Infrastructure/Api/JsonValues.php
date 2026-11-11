<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Api;

use App\Shared\Domain\Money;

/**
 * How the API writes money, days and moments (ADR 0027): money in minor units with
 * its currency, a day as YYYY-MM-DD, a moment as ISO 8601 with its offset.
 */
final class JsonValues
{
    /**
     * @return array{amount_minor: int, currency: string}
     */
    public static function money(Money $money): array
    {
        return ['amount_minor' => $money->minor(), 'currency' => $money->currency()->value];
    }

    public static function date(?\DateTimeImmutable $day): ?string
    {
        return $day?->format('Y-m-d');
    }

    public static function timestamp(?\DateTimeImmutable $moment): ?string
    {
        return $moment?->format(\DATE_ATOM);
    }
}
