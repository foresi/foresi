<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Country\CzechRepublic\Invoicing;

use App\Invoicing\Domain\Percent;
use App\Invoicing\Domain\VatRateKind;

/**
 * The Czech VAT rates as dated rows, after § 47 of the VAT Act (235/2004 Coll.,
 * https://www.zakonyprolidi.cz/cs/2004-235 and its amendment history): 21 % and
 * 15 % from 1 January 2013 (500/2012 Coll.), a second reduced 10 % added from
 * 1 January 2015 (360/2014 Coll.), and 21 % and 12 % from 1 January 2024, when
 * 15 % and 10 % were merged into 12 % and the second reduced rate ended
 * (349/2023 Coll.). Later amendments, such as the one of 1 May 2020, only moved
 * supplies between the rates, so they are no rows here.
 *
 * A date before the first row has no rate here, and a new rate is a new row,
 * never an edit, and the rows run from the oldest to the newest. A row with no
 * percent ends its kind from that date.
 */
final class CzechVatRates
{
    /** @var list<array{from: string, kind: VatRateKind, percent: ?string}> */
    private const array ROWS = [
        ['from' => '2013-01-01', 'kind' => VatRateKind::Base, 'percent' => '21'],
        ['from' => '2013-01-01', 'kind' => VatRateKind::Reduced, 'percent' => '15'],
        ['from' => '2015-01-01', 'kind' => VatRateKind::SecondReduced, 'percent' => '10'],
        ['from' => '2024-01-01', 'kind' => VatRateKind::Base, 'percent' => '21'],
        ['from' => '2024-01-01', 'kind' => VatRateKind::Reduced, 'percent' => '12'],
        ['from' => '2024-01-01', 'kind' => VatRateKind::SecondReduced, 'percent' => null],
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
        if (null === $found || null === $found['percent']) {
            throw new \DomainException(\sprintf('No %s VAT rate is defined for %s.', $kind->value, $day));
        }

        return Percent::fromString($found['percent']);
    }
}
