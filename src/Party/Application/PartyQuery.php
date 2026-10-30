<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application;

use App\Party\Domain\Party;
use App\Party\Domain\PartyRole;

/**
 * Reads parties of the active tenant. Parties of any other tenant do not exist
 * for it: they are never listed and never found.
 */
interface PartyQuery
{
    public const int PER_PAGE = 25;

    /** Sort keys; a leading "-" sorts descending. */
    public const array SORTS = ['name', 'kind', 'registration', 'vat'];

    /**
     * Matches the name, the registration number (ignoring spaces and
     * punctuation), the VAT id and the e-mail addresses. Archived parties are
     * listed only when asked for. An unknown sort falls back to the name.
     */
    public function search(string $text, ?PartyRole $role, bool $archived, string $sort, int $page): PartyPage;

    public function counts(): PartyCounts;

    public function find(int $id): ?Party;

    /**
     * @param list<int> $ids
     *
     * @return list<Party> those of the ids that belong to the active tenant
     */
    public function findMany(array $ids): array;

    /**
     * Active persons that are not contact persons of the company yet.
     *
     * @return list<Party>
     */
    public function contactPersonCandidates(Party $company): array;
}
