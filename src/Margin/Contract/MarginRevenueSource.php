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
    public const string TAG = 'foresi.margin.revenue_source';

    /**
     * The revenue documents whose tax point falls between two days, both days
     * included, in the zone of $from: not in the trash, advances left out.
     *
     * @return iterable<RevenueDocument>
     */
    public function between(\DateTimeImmutable $from, \DateTimeImmutable $to): iterable;

    /**
     * One revenue document by id, built as between() builds it; null when it
     * is unknown, an advance or in the trash.
     */
    public function document(string $id): ?RevenueDocument;

    /**
     * The revenue document that holds a line of a document, by the id of that
     * line; null as document() is.
     */
    public function documentOfLine(string $invoiceLineId): ?RevenueDocument;

    /**
     * A recurring template line by id: what it bills and whom; null when unknown.
     */
    public function templateLine(string $id): ?TemplateLine;
}
