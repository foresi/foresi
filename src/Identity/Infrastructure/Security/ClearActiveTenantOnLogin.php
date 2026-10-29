<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Application\ActiveTenantStore;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * A new sign-in starts without the tenant chosen by whoever used the session before.
 */
final readonly class ClearActiveTenantOnLogin
{
    public function __construct(private ActiveTenantStore $store)
    {
    }

    #[AsEventListener(event: LoginSuccessEvent::class)]
    public function __invoke(): void
    {
        $this->store->clear();
    }
}
