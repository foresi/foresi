<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Application\ActiveTenantStore;
use App\Identity\Application\ApiTokenContext;
use App\Tenancy\Domain\TenantId;

/**
 * The organisation of the personal API token a request signed in with. A token
 * belongs to one organisation, so there is nothing to choose, keep or forget.
 */
final readonly class TokenActiveTenantStore implements ActiveTenantStore
{
    public function __construct(private ApiTokenContext $tokens)
    {
    }

    public function get(): ?TenantId
    {
        return $this->tokens->grant()?->tenantId;
    }

    public function store(TenantId $tenant): void
    {
    }

    public function clear(): void
    {
    }

    public function migrate(): void
    {
    }
}
