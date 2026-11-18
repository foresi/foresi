<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Application;

use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyRole;

/**
 * Reads parties.
 */
interface PartyQuery
{
    public const int PER_PAGE = 25;

    /**
     * Matches the name, the registration number (ignoring spaces and
     * punctuation), the VAT id and the e-mail addresses. Archived parties are
     * listed only when asked for. A sort is the key of an attribute of the contact list that
     * its query sorts by, such as "vat_id", a leading "-" for descending; an unknown sort falls
     * back to the name.
     * Narrowed to an import, the list shows its parties whether archived or
     * not, since an import can archive them.
     */
    public function search(string $text, ?PartyRole $role, bool $archived, string $sort, int $page, ?ImportedBy $import = null): PartyPage;

    /**
     * The ids of the parties the same search lists, on every page, in name order.
     *
     * @return list<string>
     */
    public function matchingIds(string $text, ?PartyRole $role, bool $archived, ?ImportedBy $import = null): array;

    public function counts(): PartyCounts;

    public function find(string $id): ?Party;

    /**
     * @param list<string> $ids
     *
     * @return list<Party> those of the ids that exist
     */
    public function findMany(array $ids): array;

    /**
     * Active persons that are not contact persons of the company yet.
     *
     * @return list<Party>
     */
    public function contactPersonCandidates(Party $company): array;
}
