<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Lets Cash flow read documents again, to decide whether another document
 * falls in a period, to consider siblings after a document is forgotten and to
 * backfill. A document module implements it and tags it
 * "foresi.cash_flow.pairable_documents".
 */
#[AutoconfigureTag(self::TAG)]
interface PairableDocumentSource
{
    public const string TAG = 'foresi.cash_flow.pairable_documents';

    /**
     * @return PairableDocument|null null when the document is unknown to this source or trashed
     */
    public function one(string $subjectType, string $subjectId): ?PairableDocument;

    /**
     * Several documents of one type at once, so a list of pairings costs one lookup per source.
     * The unknown and the trashed are left out.
     *
     * @param list<string> $subjectIds
     *
     * @return iterable<PairableDocument>
     */
    public function many(string $subjectType, array $subjectIds): iterable;

    /**
     * The documents of one counterparty whose date falls from the first day to the last day, both included.
     *
     * @return iterable<PairableDocument>
     */
    public function of(string $counterpartyId, \DateTimeImmutable $from, \DateTimeImmutable $to): iterable;

    /**
     * The documents dated on or after $from.
     *
     * @return iterable<PairableDocument>
     */
    public function since(\DateTimeImmutable $from): iterable;
}
