<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * The VAT of what the firm plans but no document or schedule carries yet,
 * such as the planned items of Cash flow with their VAT stated. A plan counts
 * from today on, in the period of the day it is planned on. The VAT module
 * owns the contract and the module implements it in this module's
 * Integration directory (ADR 0033).
 */
#[AutoconfigureTag(self::TAG)]
interface VatPlanSource
{
    public const string TAG = 'app.vat_plan_source';

    /**
     * A stable key, such as "cash-flow.planned_vat".
     */
    public function key(): string;

    /**
     * Whether the caller may see the plans behind the amounts; without it
     * only the totals are shown.
     */
    public function mayReadDetails(): bool;

    /**
     * The VAT of the plans on the days from $from to $to, both included, in
     * the time zone of $from; nothing before today.
     *
     * @return iterable<PlannedVatAmount>
     */
    public function between(\DateTimeImmutable $from, \DateTimeImmutable $to): iterable;
}
