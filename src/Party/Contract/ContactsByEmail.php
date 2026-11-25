<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Contract;

/**
 * The contacts an e-mail address points to, for a module that receives mail (Inbox matches the
 * sender of a message to the address book).
 *
 * Unlike PartyDirectory, it does NOT read as the person signed in: it works with nobody signed
 * in, because it is called while mail is received from a background or webhook context. It
 * returns summaries only, no more than PartyDirectory does; the caller decides what it shows to
 * whom (Inbox restricts what it shows to the owner of the item). A caller that shows contacts to
 * a person checks that person's access itself.
 */
interface ContactsByEmail
{
    /**
     * @param string $email compared as the address book compares addresses: trimmed, without regard to case
     *
     * @return list<PartySummary> the contacts, archived ones left out, that have this address among
     *                            their e-mails, whatever its purpose; each once, by name, then by id
     */
    public function byAddress(string $email): array;

    /**
     * @param string $domain such as example.com, without regard to case, with or without a leading "@"
     *
     * @return list<PartySummary> the companies and sole traders (never persons), archived ones left
     *                            out, with an e-mail of exactly this domain, so sub.example.com is not
     *                            example.com; each once, by name, then by id; none for an empty domain
     */
    public function byDomain(string $domain): array;

    /**
     * The mirror of PartyDirectory::personsOf(): the companies and sole traders a person is linked
     * to as a contact person.
     *
     * @return list<PartySummary> in the order they were linked, an archived one too, flagged as
     *                            PartyDirectory::find() does; none for a company, a sole trader or an unknown id
     */
    public function companiesOfPerson(string $personId): array;
}
