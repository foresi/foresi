<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A module whose documents are paid: Invoicing for the income, Purchasing for
 * the costs (B2). It implements this contract in its own
 * Integration/TaxesAndContributions directory (ADR 0033), so the module works
 * without it.
 */
#[AutoconfigureTag(self::TAG)]
interface ActualAmountSource
{
    public const string TAG = 'app.taxes_actual_source';

    /**
     * A stable key, such as "invoicing.payments".
     */
    public function key(): string;

    /**
     * Whether the caller may see the documents behind the amounts; without it
     * only the totals are shown.
     */
    public function mayReadDetails(): bool;

    /**
     * The payments made on the days from $from to $to, both included, by
     * the day they were made. A cancelled payment is none.
     *
     * @return iterable<ActualAmount>
     */
    public function paidBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): iterable;

    /**
     * The net-to-gross ratio of each document asked for that the source knows,
     * by its id, for what is still open on it.
     *
     * @param list<string> $subjectIds
     *
     * @return array<string, VatShare>
     */
    public function sharesOf(array $subjectIds): array;
}
