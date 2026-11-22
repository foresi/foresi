<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Contract;

/**
 * The contacts of the address book as other modules read them, without its own classes: Sales
 * for the customer of a deal or an offer, Outreach for the recipients of a campaign (spec
 * "Outreach campaigns", decision 2A). It reads as the person signed in, so only the owners and
 * the members of the organisation read it, as everywhere in the address book; a guest, or no
 * one signed in, is refused with the address book's own refusal.
 */
interface PartyDirectory
{
    /**
     * @return PartySummary|null an archived contact too, flagged; null for an unknown id
     */
    public function find(string $id): ?PartySummary;

    /**
     * @throws UnknownParty
     */
    public function get(string $id): PartySummary;

    /**
     * @return list<PartyPersonSummary> the persons linked to the company, in the order they were
     *                                  linked; none for a person or an unknown id
     */
    public function personsOf(string $companyId): array;
}
