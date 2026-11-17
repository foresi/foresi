<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

use App\Banking\Domain\BankMovement;

/**
 * What the movements add up to, for the balance and the forecast. A possible duplicate counts nowhere: it may be a movement already
 * counted. Movements in another currency than their account's say the account
 * is set up wrong and count nowhere either.
 */
interface MovementLedger
{
    /**
     * The signed sum in minor units of the account's currency of the movements
     * booked after $after up to $until, both calendar days, the day of the last
     * of them, and whether the account has any movement at all.
     *
     * @return array{sumMinor: int, lastOn: \DateTimeImmutable|null, known: bool}
     */
    public function bookedAfter(string $accountId, \DateTimeImmutable $after, \DateTimeImmutable $until): array;

    /**
     * The movements of the firm booked on the days from $from to $to, both included.
     *
     * @return list<BankMovement>
     */
    public function bookedBetween(string $firmId, \DateTimeImmutable $from, \DateTimeImmutable $to): array;
}
