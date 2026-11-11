<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Application\Query;

/**
 * Reads over the received documents of the active tenant for the lists, without
 * loading them. The query services ask here after checking who asks.
 */
interface ReceivedDocumentQueries
{
    public function count(ReceivedDocumentFilter $filter): int;

    /**
     * Drafts newest first, recorded documents by the received date, newest
     * first, the trash by when it was moved there.
     *
     * @return list<ReceivedDocumentRow>
     */
    public function rows(ReceivedDocumentFilter $filter, int $offset, int $limit): array;

    /**
     * The drafts outside the trash, of every firm.
     */
    public function drafts(): int;

    /**
     * @return array<string, string> the name the newest document gives, by contact id, in name order
     */
    public function suppliers(?string $firmId): array;

    /**
     * The months with a document, by the day that places it (ReceivedDocumentFilter::monthDay).
     *
     * @return list<string> as YYYY-MM, newest first
     */
    public function months(?string $firmId): array;

    /**
     * The recorded documents of the firm outside the trash a credit note may
     * correct, newest first, at most $limit of them.
     *
     * @return list<ReceivedDocumentRow>
     */
    public function correctable(string $firmId, ?string $exceptId, int $limit): array;
}
