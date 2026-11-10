<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application\Query;

use App\Party\Domain\PartyRole;

/**
 * What a slice of the address book is narrowed to. Every field narrows; the
 * contacts that match all of them are listed.
 */
final readonly class ContactSliceFilter
{
    /**
     * @param string         $text               matches the name, the registration number, the VAT id and the e-mail addresses, as the search of the screen does
     * @param PartyRole|null $role               only the contacts with this role
     * @param bool           $archived           true lists the archived contacts alone, false those that are not archived
     * @param string|null    $registrationNumber the registration number, ignoring spaces and punctuation
     * @param string|null    $vatId              the VAT id, ignoring spaces and case
     */
    public function __construct(
        public string $text = '',
        public ?PartyRole $role = null,
        public bool $archived = false,
        public ?string $registrationNumber = null,
        public ?string $vatId = null,
    ) {
    }
}
