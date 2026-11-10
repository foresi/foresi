<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application\Query;

use App\Party\Application\ImportedBy;
use App\Party\Application\PartyAccess;
use App\Party\Application\PartyCounts;
use App\Party\Application\PartyNotAllowed;
use App\Party\Application\PartyPage;
use App\Party\Application\PartyQuery;
use App\Party\Domain\PartyRole;
use App\Shared\Domain\Id;

/**
 * The lists of contacts for the screens, the API and MCP alike: a page of the
 * address book with its counts, and a slice read by cursor. Only the owners
 * and members of the organisation read them.
 */
final readonly class ContactList
{
    public const int DEFAULT_LIMIT = 25;
    public const int MAX_LIMIT = 100;

    public function __construct(
        private PartyAccess $access,
        private PartyQuery $parties,
        private ContactQueries $queries,
    ) {
    }

    /**
     * @throws PartyNotAllowed for anyone but the owners and members of the organisation
     *
     * @see PartyQuery::search()
     */
    public function search(string $text, ?PartyRole $role, bool $archived, string $sort, int $page, ?ImportedBy $import = null): PartyPage
    {
        $this->access->denyUnlessStaff();

        return $this->parties->search($text, $role, $archived, $sort, $page, $import);
    }

    /**
     * @throws PartyNotAllowed for anyone but the owners and members of the organisation
     */
    public function counts(): PartyCounts
    {
        $this->access->denyUnlessStaff();

        return $this->parties->counts();
    }

    /**
     * The newest contacts first, those after the cursor. A cursor that is no
     * contact id lists nothing, and a limit is kept between 1 and MAX_LIMIT.
     *
     * @param string|null $afterId the id of the last row of the slice before; null for the first slice
     *
     * @throws PartyNotAllowed for anyone but the owners and members of the organisation
     */
    public function slice(ContactSliceFilter $filter, ?string $afterId = null, int $limit = self::DEFAULT_LIMIT): ContactSlice
    {
        $this->access->denyUnlessStaff();
        if (null !== $afterId && !Id::isValid($afterId)) {
            return new ContactSlice([], false);
        }
        $limit = max(1, min($limit, self::MAX_LIMIT));
        $rows = $this->queries->after($filter, $afterId, $limit + 1);

        return new ContactSlice(\array_slice($rows, 0, $limit), \count($rows) > $limit);
    }
}
