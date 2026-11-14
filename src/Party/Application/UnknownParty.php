<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application;

use App\Shared\Application\RefusalKind;
use App\Shared\Application\Refused;

/**
 * No such contact in the active tenant: it never existed, or it belongs to
 * another tenant.
 */
final class UnknownParty extends \RuntimeException implements Refused
{
    public function __construct(string $message = 'The organisation has no contact with this id. Find the id with foresi_search_contacts or GET /api/v1/contacts.')
    {
        parent::__construct($message);
    }

    public function reason(): string
    {
        return 'not_found';
    }

    public function kind(): RefusalKind
    {
        return RefusalKind::NotFound;
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
