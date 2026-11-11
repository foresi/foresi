<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Application\ActiveTenantStore;
use App\Identity\Application\ApiTokenContext;
use App\Identity\Application\ApiTokenGrant;
use App\Tenancy\Domain\TenantId;

/**
 * The tenant store of the current request: the token's organisation for a
 * request signed in with a personal API token, which never touches the
 * session; the choice kept in the session for everyone else.
 */
final readonly class RequestActiveTenantStore implements ActiveTenantStore
{
    public function __construct(
        private ApiTokenContext $tokens,
        private TokenActiveTenantStore $token,
        private SessionActiveTenantStore $session,
    ) {
    }

    public function get(): ?TenantId
    {
        return $this->current()->get();
    }

    public function store(TenantId $tenant): void
    {
        $this->current()->store($tenant);
    }

    public function clear(): void
    {
        $this->current()->clear();
    }

    public function migrate(): void
    {
        $this->current()->migrate();
    }

    private function current(): ActiveTenantStore
    {
        return $this->tokens->grant() instanceof ApiTokenGrant ? $this->token : $this->session;
    }
}
