<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Contract;

/**
 * Adding a contact person to a company or sole trader from another module, without the
 * address book's own classes: Sales, when a deal's dialog adds a person to its client. The
 * same two ways as the contact's own dialog: a person of the address book linked, or a new
 * person written and linked in one go. The person becomes the company's primary contact
 * person when it has none yet. It acts as the person signed in, with the address book's own
 * rules: only the owners and the members of the organisation write, and a guest is refused
 * with the address book's own refusal. A write joins the unit of work it is called in.
 */
interface ContactPersonWrites
{
    /**
     * @return list<PartyPersonSummary> the persons of the address book, not archived, that the
     *                                  company does not list yet, by name; none for a person or
     *                                  an unknown id
     */
    public function candidatesOf(string $companyId): array;

    /**
     * Links one of the candidates to the company.
     *
     * @throws UnknownParty         for an unknown company
     * @throws ContactPersonRefused person, when it is not one of the candidates or the company is a person; position
     */
    public function link(string $companyId, string $personId, string $position): PartyPersonSummary;

    /**
     * A new person of the address book, with no address of its own, linked to the company.
     *
     * @throws UnknownParty         for an unknown company
     * @throws ContactPersonRefused with every field that is wrong: name, email, position, or person when the company is a person
     */
    public function addNew(string $companyId, string $name, string $email, string $position): PartyPersonSummary;
}
