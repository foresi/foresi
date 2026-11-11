<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Mcp;

use App\Shared\Application\Api\ScopeGuard;

/**
 * A scope guard that allows or refuses everything and remembers what was asked.
 */
final class FakeScopes implements ScopeGuard
{
    public bool $refuse = false;
    /** @var list<string> */
    public array $asked = [];

    public function require(string $scope): void
    {
        $this->asked[] = $scope;
        if ($this->refuse) {
            throw new \DomainException('no scope');
        }
    }
}
