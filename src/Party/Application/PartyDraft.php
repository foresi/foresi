<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application;

/**
 * What a person or a client entered for a contact, as text, before the rules
 * are applied: the form reader and the API both fill one. The names are the
 * fields the refusals name.
 */
final readonly class PartyDraft
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
        public string $kind,
        public string $displayName,
        public string $registrationCountry,
        public string $registrationNumber,
        public string $vatId,
        public string $email,
        public string $phone,
        public string $mobile,
        public string $website,
        public array $roles,
        public string $street,
        public string $city,
        public string $postalCode,
        public string $countryCode,
        public string $privateNote,
    ) {
    }
}
