<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application\Query;

/**
 * A person who is a contact person of a company, with the company.
 */
final readonly class ContactPersonLink
{
    public function __construct(
        public string $id,
        public string $companyId,
        public string $companyName,
        public string $personId,
        public string $personName,
        public string $position,
        public bool $primary,
    ) {
    }
}
