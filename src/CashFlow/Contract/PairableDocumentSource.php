<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Contract;

/**
 * Lets Cash flow read documents again, to decide whether another document
 * falls in a period, to consider siblings after a document is forgotten and to
 * backfill. A document module implements it and tags it
 * "app.cash_flow_pairable_documents".
 */
interface PairableDocumentSource
{
    /**
     * @return PairableDocument|null null when the document is unknown to this source or trashed
     */
    public function one(string $subjectType, string $subjectId): ?PairableDocument;

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
