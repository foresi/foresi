<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application;

use App\Shared\Application\RefusalKind;
use App\Shared\Application\Refused;

/**
 * The signed-in person may not read the address book of the organisation:
 * nobody is signed in, or they are a guest. The screens and the API answer it
 * as forbidden.
 */
final class PartyNotAllowed extends \RuntimeException implements Refused
{
    public function __construct()
    {
        parent::__construct('Only the owners and members of the organisation work with its contacts.');
    }

    public function reason(): string
    {
        return 'forbidden';
    }

    public function kind(): RefusalKind
    {
        return RefusalKind::Forbidden;
    }

    public function field(): ?string
    {
        return null;
    }

    public function details(): array
    {
        return [];
    }
}
