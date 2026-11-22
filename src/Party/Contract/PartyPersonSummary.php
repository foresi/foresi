<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Contract;

/**
 * A contact person of a company, as another module addresses them.
 */
final readonly class PartyPersonSummary
{
    /**
     * @param string|null $email    the person's general e-mail address
     * @param string|null $phone    the person's phone, else their mobile
     * @param string|null $position the position the link to the company gives, such as "Director"
     */
    public function __construct(
        public string $id,
        public string $displayName,
        public ?string $email,
        public ?string $phone,
        public ?string $position,
    ) {
    }
}
