<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Application;

use Foresi\Party\Contract\PartyPersonSummary;
use Foresi\Party\Contract\UnknownParty;

/**
 * Port: adding a contact person to a deal's client from the deal's dialog, the two ways the
 * address book offers: a person of the address book linked, or a new person written and
 * linked in one go. The address book answers it (Integration/Contacts); without it a deal
 * names only the contact persons its client already has, and the dialog offers no new one.
 * A write joins the unit of work it is called in.
 */
interface ClientPersons
{
    /**
     * @return list<PartyPersonSummary> the persons the client may take, by name
     */
    public function candidatesOf(string $clientId): array;

    /**
     * @throws UnknownParty        for an unknown client
     * @throws ClientPersonRefused person, when it is not one of the candidates; position
     */
    public function link(string $clientId, string $personId, string $position): PartyPersonSummary;

    /**
     * @throws UnknownParty        for an unknown client
     * @throws ClientPersonRefused with every field that is wrong: name, email, position or person
     */
    public function addNew(string $clientId, string $name, string $email, string $position): PartyPersonSummary;
}
