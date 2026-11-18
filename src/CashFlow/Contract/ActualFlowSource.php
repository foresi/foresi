<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A module that tells the forecast what already happened (ADR 0033): the
 * documents of a firm, paid or not, and the bank movements booked. A planned
 * occurrence is left out when a document of its counterparty in its direction
 * sits in its period, or when a movement meets its match rule (C13, C16).
 * Whatever it throws is caught: the forecast lists the source as unavailable
 * and suppresses nothing by it.
 */
#[AutoconfigureTag(self::ACTUAL_TAG)]
interface ActualFlowSource
{
    public const string ACTUAL_TAG = 'app.cash_flow_actual_source';

    /**
     * A stable key, such as "invoicing.invoices", lower case with dots. A class
     * that is also a CashFlowSource gives the same key for both.
     */
    public function key(): string;

    /**
     * What happened for the firm between the two days, both included, dated by
     * the issue of a document or the booking of a movement. Drafts and the trash
     * stay out.
     *
     * @return iterable<ActualFlow>
     */
    public function actuals(string $firmId, \DateTimeImmutable $from, \DateTimeImmutable $to): iterable;
}
