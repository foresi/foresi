<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A module whose documents carry costs: Purchasing for the documents it
 * receives. The Margin module owns the contract and the module implements it
 * in its own Integration/Margin directory (ADR 0033), so Margin works without
 * it.
 */
#[AutoconfigureTag(self::TAG)]
interface MarginCostSource
{
    public const string TAG = 'app.margin_cost_source';

    /**
     * The cost document with this id, or null when this source has none.
     */
    public function document(string $id): ?CostDocument;

    /**
     * The ids of every document that has costs now (not a draft, not trashed).
     *
     * @return iterable<string>
     */
    public function documentIds(): iterable;

    /**
     * The ids of the documents that may have a line without a base in CZK
     * because they are in a foreign currency with no exchange rate: a cheap
     * short list, never a miss, that the caller checks one document at a time.
     *
     * @return iterable<string>
     */
    public function documentIdsWithoutExchangeRate(): iterable;

    /**
     * The ids of the documents of a supplier whose month is $fromMonth or later.
     *
     * @return iterable<string>
     */
    public function documentsOf(string $supplierId, \DateTimeImmutable $fromMonth): iterable;
}
