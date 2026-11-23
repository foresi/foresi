<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Application;

use Foresi\Party\Contract\PartyDirectory;
use Foresi\Party\Contract\PartyPersonSummary;
use Foresi\Sales\Domain\Deal;
use Foresi\Sales\Domain\DealContact;
use Foresi\Sales\Domain\DealStatus;
use Foresi\Sales\Domain\LostReason;
use Foresi\Sales\Domain\Stage;

/**
 * What the screens, the API and MCP read of Sales, for the owners and members
 * of the organisation only. A list leaves out the deals in the trash; one deal
 * read by its id is shown from the trash too, so it can be restored.
 */
final readonly class DealReads
{
    /** The longest text a search looks for; a longer one is cut. */
    public const int MAX_TEXT = 100;

    public function __construct(
        private SalesAccess $access,
        private SalesStore $store,
        private PartyDirectory $parties,
    ) {
    }

    /**
     * The deals not in the trash, the newest first, narrowed by each filter given.
     *
     * @param string|null $status open, won or lost
     * @param string|null $text   held by the title, without regard to case; empty for any
     *
     * @return list<Deal>
     *
     * @throws SalesRefused forbidden, invalid status_unknown
     */
    public function deals(?string $status = null, ?string $stageId = null, ?string $ownerUserId = null, ?string $clientPartyId = null, ?string $text = null): array
    {
        $this->access->denyUnlessStaff();
        $text = mb_substr(trim($text ?? ''), 0, self::MAX_TEXT);
        $parsed = null === $status ? null : (DealStatus::tryFrom($status) ?? throw SalesRefused::invalid('status_unknown', 'status', 'The status is open, won or lost.'));

        return $this->store->deals(new DealFilter($parsed, $stageId, $ownerUserId, $clientPartyId, '' === $text ? null : $text));
    }

    /**
     * The deals of a client, the newest first, as its Deals tab shows them.
     *
     * @return list<Deal>
     *
     * @throws SalesRefused forbidden
     */
    public function dealsOfClient(string $clientPartyId): array
    {
        return $this->deals(clientPartyId: $clientPartyId);
    }

    /**
     * @throws SalesRefused forbidden, not_found
     */
    public function deal(string $id): DealDetail
    {
        $this->access->denyUnlessStaff();
        $deal = $this->store->deal($id) ?? throw SalesRefused::notFound('There is no deal with this id. Find it with foresi_search_deals.');
        $lines = $this->store->linesOf($deal->id());
        $links = [];
        foreach ($this->store->linksOf($deal->id()) as $link) {
            $outgoing = $link->fromDealId() === $deal->id();
            $other = $this->store->deal($outgoing ? $link->toDealId() : $link->fromDealId());
            if ($other instanceof Deal) {
                $links[] = new DealLinkView($link, $other, $outgoing);
            }
        }
        $ids = array_map(static fn (DealContact $contact): string => $contact->personId(), $this->store->contactsOf($deal->id()));
        $linked = [];
        foreach ($this->parties->personsOf($deal->clientPartyId()) as $person) {
            $linked[$person->id] = $person;
        }
        $persons = array_values(array_filter(array_map(static fn (string $id): ?PartyPersonSummary => $linked[$id] ?? null, $ids)));
        $reasonId = $deal->lostReasonId();

        return new DealDetail(
            $deal,
            $this->store->stage($deal->stageId()),
            null === $reasonId ? null : $this->store->lostReason($reasonId),
            $lines,
            DealValues::of($lines),
            $links,
            $ids,
            $persons,
        );
    }

    /**
     * @return list<Stage> in their order; the archived ones after the others, when asked for
     *
     * @throws SalesRefused forbidden
     */
    public function stages(bool $includeArchived = false): array
    {
        $this->access->denyUnlessStaff();

        return $this->store->stages($includeArchived);
    }

    /**
     * @return list<LostReason> in their order; the archived ones after the others, when asked for
     *
     * @throws SalesRefused forbidden
     */
    public function lostReasons(bool $includeArchived = false): array
    {
        $this->access->denyUnlessStaff();

        return $this->store->lostReasons($includeArchived);
    }
}
