<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\ExchangeRate\Application;

/**
 * Where rates come from, such as the ČNB. The source answers for a date with its
 * last publication on or before it.
 */
interface RateSource
{
    /**
     * A stable name, stored with the rates.
     */
    public function name(): string;

    /**
     * The time zone of the source's publication time.
     */
    public function zone(): \DateTimeZone;

    /**
     * The time of day, as H:i in the zone of the source, after which the day's
     * publication is expected to be out.
     */
    public function expectedAt(): string;

    /**
     * The time of day, as H:i in the zone of the source, after which a day
     * without a publication is known to have none (a holiday).
     */
    public function settledAt(): string;

    /**
     * @return PublishedRates|null null when the source could not be reached or its answer could not be read
     */
    public function fetch(\DateTimeImmutable $date): ?PublishedRates;
}
