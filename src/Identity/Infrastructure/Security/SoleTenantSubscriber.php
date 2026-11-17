<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Application\MembershipDirectory;
use App\Identity\Domain\Role;
use App\Instance\Application\SoleTenant;
use App\Instance\Application\TenantContext;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * One instance serves one customer (ADR 0035), so there is nothing to choose:
 * a signed-in user with an active membership works in the one tenant, whether
 * signed in on a page or with a personal API token. Without a membership no
 * tenant is set, so what is still tenant-owned stays unreachable. It goes with
 * the tenant itself.
 */
final readonly class SoleTenantSubscriber
{
    public function __construct(
        private TokenStorageInterface $tokens,
        private MembershipDirectory $memberships,
        private SoleTenant $tenant,
        private TenantContext $context,
    ) {
    }

    // After the firewall (priority 8) has authenticated the user.
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 0)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || $this->context->has()) {
            return;
        }
        $user = $this->tokens->getToken()?->getUser();
        if (!$user instanceof SecurityUser || !$this->memberships->roleOf($user->id()) instanceof Role) {
            return;
        }

        $this->context->set($this->tenant->id());
    }
}
