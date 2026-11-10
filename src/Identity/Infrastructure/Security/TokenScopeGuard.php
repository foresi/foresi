<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Application\ApiTokenContext;
use App\Identity\Domain\ApiScope;
use App\Shared\Application\Api\ScopeGuard;

/**
 * The scope check of the shared contract, answered by the personal API token.
 */
final readonly class TokenScopeGuard implements ScopeGuard
{
    public function __construct(private ApiTokenContext $context)
    {
    }

    public function require(string $scope): void
    {
        $this->context->requireScope(ApiScope::from($scope));
    }
}
