<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Integration\CashFlow;

use Foresi\Deals\Application\SalesStore;
use Foresi\Deals\Domain\BillingPlanEntry;
use Foresi\Deals\Domain\Deal;
use Foresi\Deals\Domain\DealLine;
use Foresi\Party\Contract\PartyDirectory;
use Foresi\Party\Contract\PartySummary;

/**
 * The deals, lines and clients of one read of the plan, each loaded once.
 */
final class PlanContext
{
    /** @var array<string, Deal|null> */
    private array $deals = [];
    /** @var array<string, array<string, DealLine>> */
    private array $lines = [];
    /** @var array<string, int> */
    private array $dueDays = [];

    public function __construct(private readonly SalesStore $store, private readonly PartyDirectory $parties)
    {
    }

    public function deal(BillingPlanEntry $entry): ?Deal
    {
        $id = $entry->dealId();
        if (!\array_key_exists($id, $this->deals)) {
            $this->deals[$id] = $this->store->deal($id);
        }

        return $this->deals[$id];
    }

    public function line(BillingPlanEntry $entry): ?DealLine
    {
        $lineId = $entry->lineId();
        if (null === $lineId) {
            return null;
        }
        $dealId = $entry->dealId();
        if (!isset($this->lines[$dealId])) {
            $this->lines[$dealId] = [];
            foreach ($this->store->linesOf($dealId) as $line) {
                $this->lines[$dealId][$line->id()] = $line;
            }
        }

        return $this->lines[$dealId][$lineId] ?? null;
    }

    /** The client's due days, the standard 14 for a client the caller does not see. */
    public function dueDays(Deal $deal): int
    {
        $client = $deal->clientPartyId();
        if (!isset($this->dueDays[$client])) {
            $party = $this->parties->find($client);
            $this->dueDays[$client] = $party instanceof PartySummary ? $party->dueDays : 14;
        }

        return $this->dueDays[$client];
    }
}
