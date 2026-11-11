<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application\Query;

/**
 * Reads the address book of the active tenant for the API. Anything of another
 * tenant does not exist for it. Call it through ContactList, which asks who may.
 */
interface ContactQueries
{
    /**
     * @param int $limit at most this many rows
     *
     * @return list<ContactRow> newest first, only those older than the cursor
     */
    public function after(ContactSliceFilter $filter, ?string $afterId, int $limit): array;
}
