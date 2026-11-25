<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\ExchangeRate\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Where rates come from, such as the ČNB or the ECB. The source answers for a
 * date with its last publication on or before it. Any module may offer one
 * (tag "foresi.exchange_rate.source"); several can be installed at once, and
 * the firm uses one of them: the one it chose, else the default of its
 * country's RateRules, else the first installed. The Exchange rate module
 * offers the ECB itself, after every other source.
 */
#[AutoconfigureTag(self::TAG)]
interface RateSource
{
    public const string TAG = 'foresi.exchange_rate.source';

    /**
     * A stable name, stored with the rates and chosen in the settings, such as
     * "cnb": lower case letters, digits and underscores, at most 16 characters.
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
