<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Application;

use Foresi\Sales\Application\Api\AddDealLinkInput;
use Foresi\Sales\Application\Api\RemoveDealLinkInput;
use Foresi\Sales\Contract\Event\DealUpdated;
use Foresi\Sales\Domain\Deal;
use Foresi\Sales\Domain\DealLink;
use Foresi\Sales\Domain\DealLinkType;
use Foresi\Shared\Application\Event\EventRecorder;

/**
 * The links between deals: two deals of the same client, never a deal to
 * itself, and a pair once, whichever way round. Each change records
 * sales.deal.updated for both deals.
 */
final readonly class DealLinkWrites
{
    public function __construct(
        private SalesAccess $access,
        private SalesStore $store,
        private SalesWork $work,
        private EventRecorder $events,
        private SalesClock $clock,
    ) {
    }

    /**
     * Records that the deal follows from the other.
     *
     * @throws SalesRefused
     */
    public function add(AddDealLinkInput $input): DealLink
    {
        $this->access->denyUnlessStaff();

        return $this->work->run(function () use ($input): DealLink {
            $from = $this->deal($input->dealId);
            $type = DealLinkType::tryFrom($input->type) ?? throw SalesRefused::invalid('link_type_unknown', 'type', 'A deal follows_from another; there is no other type of link.');
            if ($input->toDealId === $from->id()) {
                throw SalesRefused::invalid('link_self', 'toDealId', 'A deal is not linked to itself.');
            }
            $to = $this->deal($input->toDealId);
            if ($to->clientPartyId() !== $from->clientPartyId()) {
                throw SalesRefused::invalid('link_other_client', 'toDealId', 'Only two deals of the same client are linked.');
            }
            foreach ($this->store->linksOf($from->id()) as $link) {
                if ($link->type() === $type && $link->joins($to->id())) {
                    throw SalesRefused::conflict('link_duplicate', 'The two deals are linked already.', 'toDealId');
                }
            }
            $link = new DealLink($from->id(), $to->id(), $type);
            $this->store->add($link);
            $this->updated($from);
            $this->updated($to);

            return $link;
        });
    }

    /**
     * Removes a link of the deal; both deals stay.
     *
     * @throws SalesRefused
     */
    public function remove(RemoveDealLinkInput $input): Deal
    {
        $this->access->denyUnlessStaff();

        return $this->work->run(function () use ($input): Deal {
            $deal = $this->deal($input->dealId);
            $links = array_values(array_filter($this->store->linksOf($deal->id()), static fn (DealLink $link): bool => $link->id() === $input->linkId));
            $link = $links[0] ?? throw SalesRefused::notFound('The deal has no link with this id. Read the deal with foresi_get_deal.');
            $this->store->remove($link);
            $this->updated($deal);
            $other = $this->store->deal($link->fromDealId() === $deal->id() ? $link->toDealId() : $link->fromDealId());
            if ($other instanceof Deal) {
                $this->updated($other);
            }

            return $deal;
        });
    }

    /**
     * @throws SalesRefused not_found
     */
    private function deal(string $id): Deal
    {
        $deal = $this->store->deal($id);
        if (!$deal instanceof Deal || $deal->isTrashed()) {
            throw SalesRefused::notFound('There is no deal with this id. Find it with foresi_search_deals.');
        }

        return $deal;
    }

    private function updated(Deal $deal): void
    {
        $deal->touch($this->clock->now());
        $this->events->record(new DealUpdated($deal->id(), $deal->status()->value, $deal->stageId()));
    }
}
