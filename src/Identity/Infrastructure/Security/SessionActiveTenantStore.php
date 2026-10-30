<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Application\ActiveTenantStore;
use App\Tenancy\Domain\TenantId;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class SessionActiveTenantStore implements ActiveTenantStore
{
    private const string KEY = 'active_tenant';

    public function __construct(private RequestStack $requests)
    {
    }

    public function get(): ?TenantId
    {
        $value = $this->requests->getSession()->get(self::KEY);

        if (!\is_string($value)) {
            return null;
        }

        try {
            return TenantId::fromString($value);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    public function store(TenantId $tenant): void
    {
        $this->requests->getSession()->set(self::KEY, $tenant->toString());
    }

    public function clear(): void
    {
        $this->requests->getSession()->remove(self::KEY);
    }

    public function migrate(): void
    {
        $this->requests->getSession()->migrate(true);
    }
}
