<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Country\CzechRepublic\Invoicing;

use App\Invoicing\Domain\Percent;
use App\Invoicing\Domain\VatRateKind;

/**
 * The Czech VAT rates as dated rows: 21 % and 12 % since 1 January 2024
 * (the earlier 15 % and 10 % were merged into 12 %). A date before the first
 * row has no rate here, and a new rate is a new row, never an edit, and
 * the rows run from the oldest to the newest.
 */
final class CzechVatRates
{
    /** @var list<array{from: string, kind: VatRateKind, percent: string}> */
    private const array ROWS = [
        ['from' => '2024-01-01', 'kind' => VatRateKind::Base, 'percent' => '21'],
        ['from' => '2024-01-01', 'kind' => VatRateKind::Reduced, 'percent' => '12'],
    ];

    /**
     * @throws \DomainException when no row of that kind is valid on that date
     */
    public function rate(VatRateKind $kind, \DateTimeImmutable $on): Percent
    {
        $day = $on->format('Y-m-d');
        $found = null;
        foreach (self::ROWS as $row) {
            if ($row['kind'] === $kind && $row['from'] <= $day) {
                $found = $row;
            }
        }
        if (null === $found) {
            throw new \DomainException(\sprintf('No %s VAT rate is defined for %s.', $kind->value, $day));
        }

        return Percent::fromString($found['percent']);
    }
}
