<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Contract;

/**
 * Port: where the address book shows a party, for the modules that name one (an
 * invoice's customer, a deal's client, a project's client). Party declares it and
 * has no address book; the address book answers it, and without it every page is
 * null, so a party is shown as plain text, not as a link. Inject it nullable.
 */
interface ContactPages
{
    /**
     * The page of a contact.
     *
     * @param array<string, string> $query more of the page's address, such as a tab
     */
    public function urlFor(string $id, array $query = []): ?string;

    /**
     * The form that edits a contact.
     */
    public function editUrlFor(string $id): ?string;

    /**
     * The form of a new contact.
     *
     * @param array<string, string> $query the values the form opens with, as the address book's own form takes them
     */
    public function newUrl(array $query = []): ?string;
}
