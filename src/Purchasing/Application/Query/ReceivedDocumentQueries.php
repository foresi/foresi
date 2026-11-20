<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application\Query;

use Foresi\Purchasing\Domain\ReceivedDocument;

/**
 * Reads over the received documents for the lists, without
 * loading them. The query services ask here after checking who asks.
 */
interface ReceivedDocumentQueries
{
    /**
     * The documents the filter lists, and the count of each view it counts, by key,
     * all in one query within the firm, the month and the text (spec D9).
     *
     * @return array{int, array<string, int>}
     */
    public function counts(ReceivedDocumentFilter $filter): array;

    /**
     * Drafts newest first, the trash by when it was moved there, any other
     * view by the received date, newest first.
     *
     * @return list<ReceivedDocumentRow>
     */
    public function rows(ReceivedDocumentFilter $filter, int $offset, int $limit): array;

    /**
     * The drafts outside the trash.
     */
    public function drafts(): int;

    /**
     * The months with a document, by the day that places it (ReceivedDocumentFilter::monthDay).
     *
     * @return list<string> as YYYY-MM, newest first
     */
    public function months(): array;

    /**
     * The recorded documents of the firm outside the trash a credit note may
     * correct, newest first, at most $limit of them.
     *
     * @return list<ReceivedDocumentRow>
     */
    public function correctable(?string $exceptId, int $limit): array;

    /**
     * The documents of a slice for the API, newest first by id, after the
     * document with $afterId when given, and whether more follow.
     *
     * @return array{list<ReceivedDocument>, bool}
     *
     * @throws \InvalidArgumentException for a limit below one, or an id that is none
     */
    public function slice(ReceivedDocumentSliceFilter $filter, ?string $afterId, int $limit): array;
}
