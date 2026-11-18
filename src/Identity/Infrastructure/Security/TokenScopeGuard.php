<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Infrastructure\Security;

use Foresi\Identity\Application\ApiTokenContext;
use Foresi\Identity\Application\ApiTokenGrant;
use Foresi\Identity\Application\InsufficientScope;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Shared\Application\Api\ScopeGuard;

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
        if (self::ANY === $scope) {
            if (!$this->context->grant() instanceof ApiTokenGrant) {
                throw new InsufficientScope(ApiScope::read());
            }

            return;
        }
        $this->context->requireScope(ApiScope::from($scope));
    }

    public function allows(string $scope): bool
    {
        $grant = $this->context->grant();
        if (!$grant instanceof ApiTokenGrant) {
            return false;
        }

        return self::ANY === $scope || $grant->allows(ApiScope::from($scope));
    }
}
