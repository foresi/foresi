<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\TaxesAndContributions\Support;

use Foresi\Identity\Application\ApiTokenContext;
use Foresi\Identity\Application\ApiTokenGrant;
use Foresi\Identity\Domain\ApiScope;

/**
 * A personal API token with the scopes given.
 */
final readonly class FixedToken implements ApiTokenContext
{
    /**
     * @param list<ApiScope> $scopes
     */
    public function __construct(private array $scopes)
    {
    }

    public function grant(): ApiTokenGrant
    {
        return new ApiTokenGrant('019a0000-0000-7000-8000-000000000001', '019a0000-0000-7000-8000-0000000000aa', 'Example', $this->scopes, new \DateTimeImmutable('2026-12-01'));
    }

    public function requireScope(ApiScope $scope): ApiTokenGrant
    {
        return $this->grant();
    }
}
