<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Contract;

use Foresi\Shared\Contract\Refused;

/**
 * Creates a contact for another module (Inbox creates one from a message), under the same rules,
 * staff check, audit, events and duplicate refusal as the address book's own create.
 */
interface ContactCreation
{
    /**
     * @return string the id of the new contact
     *
     * @throws Refused the address book's own refusal, unchanged: a field that is missing or wrong,
     *                 or a duplicate that carries the ids it matched (details existing_ids)
     */
    public function create(NewContact $contact): string;
}
