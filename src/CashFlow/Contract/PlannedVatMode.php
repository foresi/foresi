<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Contract;

/**
 * How the amount of a planned item stands to its VAT: no VAT stated, the
 * amount includes the VAT, the VAT comes on top of it, or the firm as the
 * recipient self-assesses it (reverse charge: a domestic reverse charge, or a
 * service from the EU or from outside it). A reverse charge moves the amount
 * as stated and counts the same VAT as output and as input, and is for money
 * out only. The VAT counts only on the days the firm is a VAT payer; on other
 * days the amount is as stated.
 */
enum PlannedVatMode: string
{
    case None = 'none';
    case Included = 'included';
    case Excluded = 'excluded';
    case ReverseCharge = 'reverse_charge';

    /**
     * The modes a planned item in the direction may take: a reverse charge is the recipient's.
     *
     * @return list<self>
     */
    public static function for(FlowDirection $direction): array
    {
        return FlowDirection::In === $direction ? [self::None, self::Included, self::Excluded] : self::cases();
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $mode): string => $mode->value, self::cases());
    }
}
