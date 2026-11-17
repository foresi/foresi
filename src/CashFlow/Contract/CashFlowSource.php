<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\CashFlow\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A module that tells the forecast what money it expects (ADR 0033). It is
 * collected by its tag, so a module implements it in its Integration/CashFlow
 * directory and Cash flow knows no source by name. A source answers only for
 * what the caller may read, inside the tenant filter. Whatever it throws is
 * caught: the forecast lists the source as unavailable and goes on.
 */
#[AutoconfigureTag(self::TAG)]
interface CashFlowSource
{
    public const string TAG = 'app.cash_flow_source';

    /**
     * A stable key, such as "invoicing.invoices", lower case with dots.
     */
    public function key(): string;

    /**
     * The open flows of the firm expected by $to. A flow still open with a date
     * before $today is returned too; the forecast counts it as overdue.
     *
     * @param \DateTimeImmutable $from  the first day of the forecast, $today or later
     * @param \DateTimeImmutable $to    the last day of the forecast, inclusive
     * @param \DateTimeImmutable $today midnight of today in the tenant's zone (TenantClock)
     *
     * @return iterable<ExpectedFlow>
     */
    public function flows(string $firmId, \DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $today): iterable;
}
