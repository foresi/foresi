<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A module whose documents earn revenue: Invoicing for the documents it
 * issues. The Margin module owns the contract and the module implements it in
 * its own Integration/Margin directory (ADR 0033), so Margin works without it.
 */
#[AutoconfigureTag(self::TAG)]
interface MarginRevenueSource
{
    public const string TAG = 'app.margin_revenue_source';

    /**
     * The revenue documents whose tax point falls between two days, both days
     * included, in the zone of $from: not in the trash, advances left out.
     *
     * @return iterable<RevenueDocument>
     */
    public function between(\DateTimeImmutable $from, \DateTimeImmutable $to): iterable;
}
