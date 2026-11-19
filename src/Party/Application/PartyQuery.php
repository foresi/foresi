<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Application;

use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyRole;
use Foresi\Shared\Application\View\ViewFilter;

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
     * The list's views (spec D3): what the search and the import leave, in the view, with
     * the count of each view of $counted (D9), all counted in one query. The view decides
     * whether archived parties show, unless the list is narrowed to an import.
     *
     * @param array<string, ViewFilter> $counted the views that show a count, by key
     */
    public function inView(string $text, ViewFilter $view, array $counted, string $sort, int $page, ?ImportedBy $import = null): PartyPage;

    /**
     * The ids of the parties the same search lists, on every page, in name order; with a
     * view, the parties it holds besides.
     *
     * @return list<string>
     */
    public function matchingIds(string $text, ?PartyRole $role, bool $archived, ?ImportedBy $import = null, ?ViewFilter $view = null): array;

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
