<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Domain;

/**
 * How a planned invoice of a won deal is billed: once, for an amount, or by
 * one recurring line of the deal, whose amount and period it follows.
 */
enum BillingEntryKind: string
{
    case OneOff = 'one_off';
    case Recurring = 'recurring';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $kind): string => $kind->value, self::cases());
    }
}
