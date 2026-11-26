<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Identity\Support;

use Foresi\Identity\Application\GuestScopes;
use Foresi\Shared\Application\Api\ApiResources;

/**
 * Resources as a contacts module and a work module declare them: a guest
 * may change tasks and read projects, and nothing of contacts (ADR 0041).
 */
final readonly class WorkApiResources implements ApiResources
{
    public function declared(): array
    {
        return ['contacts', 'projects', 'tasks'];
    }

    public function byModule(): array
    {
        return ['party' => ['contacts'], 'work' => ['projects', 'tasks']];
    }

    public function guest(): array
    {
        return ['projects:read', 'tasks:write'];
    }

    public static function guestScopes(): GuestScopes
    {
        return new GuestScopes(new self());
    }
}
