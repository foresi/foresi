<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Country\CzechRepublic\Invoicing;

use App\Invoicing\Application\SupplyCode;

/**
 * The codes of the supplies taxed by the customer, as the instructions for the
 * control statement list them (Financial Administration, methodological
 * information of 1 January 2024), each with the day it takes effect.
 */
final class CzechSupplyCodes
{
    /** @var list<array{from: string, code: string}> */
    private const array ROWS = [
        ['from' => '2016-01-01', 'code' => '1'],
        ['from' => '2017-07-01', 'code' => '1a'],
        ['from' => '2016-01-01', 'code' => '3'],
        ['from' => '2017-07-01', 'code' => '3a'],
        ['from' => '2016-01-01', 'code' => '4'],
        ['from' => '2017-07-01', 'code' => '4a'],
        ['from' => '2016-01-01', 'code' => '5'],
        ['from' => '2017-07-01', 'code' => '6'],
        ['from' => '2017-07-01', 'code' => '7'],
        ['from' => '2016-01-01', 'code' => '11'],
        ['from' => '2016-01-01', 'code' => '12'],
        ['from' => '2016-01-01', 'code' => '13'],
        ['from' => '2016-01-01', 'code' => '14'],
        ['from' => '2016-01-01', 'code' => '15'],
        ['from' => '2016-01-01', 'code' => '16'],
        ['from' => '2016-01-01', 'code' => '17'],
        ['from' => '2016-02-01', 'code' => '18'],
        ['from' => '2016-02-01', 'code' => '19'],
        ['from' => '2016-02-01', 'code' => '20'],
        ['from' => '2016-10-01', 'code' => '21'],
    ];

    /**
     * @return list<SupplyCode>
     */
    public function on(\DateTimeImmutable $on): array
    {
        $day = $on->format('Y-m-d');
        $codes = [];
        foreach (self::ROWS as $row) {
            if ($row['from'] <= $day) {
                $codes[] = new SupplyCode($row['code'], 'invoice.supply_code.'.$row['code']);
            }
        }

        return $codes;
    }
}
