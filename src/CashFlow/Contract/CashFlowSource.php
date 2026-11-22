<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A module that tells the forecast what money it expects (ADR 0033). It is
 * collected by its tag, so a module implements it in its Integration/CashFlow
 * directory and Cash flow knows no source by name. A source says whether the
 * caller may read its documents: without that, the forecast counts its flows
 * in the category totals only (C15).
 * Whatever it throws is caught: the forecast lists the source as unavailable
 * and goes on.
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
     * Whether the caller may read the documents behind the flows, such as an API
     * token with the read scope of the source's module. When not, the forecast
     * shows only the category totals of its flows, never a document.
     */
    public function mayReadDetails(): bool;

    /**
     * The open flows of the firm expected by $to. A flow still open with a date
     * before $today is returned too; the forecast counts it on the first day as
     * overdue, or without overdue when the source flags it awaited
     * (FlowFlag::Awaited): its date has passed, but it is not late, such as a
     * tax whose final amount only the return knows.
     *
     * @param \DateTimeImmutable $from  the first day of the forecast, $today or later
     * @param \DateTimeImmutable $to    the last day of the forecast, inclusive
     * @param \DateTimeImmutable $today midnight of today in the instance's zone (InstanceClock)
     *
     * @return iterable<ExpectedFlow>
     */
    public function flows(\DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $today): iterable;
}
