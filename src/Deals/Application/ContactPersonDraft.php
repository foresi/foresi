<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Application;

/**
 * A person the deal's dialog adds to the client while it saves the deal: one of the address
 * book (its id), or a new one (its name and e-mail), with the position the link gives. The
 * person is linked to the client and becomes one of the deal's contact persons. Only the
 * screens send one; the API and MCP link persons in the address book themselves.
 */
final readonly class ContactPersonDraft
{
    public function __construct(
        public ?string $personId,
        public string $name = '',
        public string $email = '',
        public string $position = '',
    ) {
    }

    public static function existing(string $personId, string $position = ''): self
    {
        return new self($personId, position: $position);
    }

    public static function new(string $name, string $email = '', string $position = ''): self
    {
        return new self(null, $name, $email, $position);
    }

    public function isExisting(): bool
    {
        return null !== $this->personId;
    }
}
