<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Contract;

/**
 * How the amount of a planned item stands to its VAT: no VAT stated, the
 * amount includes the VAT, or the VAT comes on top of it. The VAT counts only
 * on the days the firm is a VAT payer; on other days the amount is as stated.
 */
enum PlannedVatMode: string
{
    case None = 'none';
    case Included = 'included';
    case Excluded = 'excluded';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $mode): string => $mode->value, self::cases());
    }
}
