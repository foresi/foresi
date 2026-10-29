<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Application\ActiveTenantStore;
use App\Identity\Application\MembershipCheck;
use App\Identity\Application\MembershipDirectory;
use App\Identity\Application\UserRepository;
use App\Identity\Domain\User;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Activates the signed-in user's tenant on every request. Without a valid
 * choice no tenant is set, so tenant-owned data stays unreachable.
 */
final readonly class ActiveTenantSubscriber
{
    public function __construct(
        private TokenStorageInterface $tokens,
        private UserRepository $users,
        private MembershipDirectory $memberships,
        private ActiveTenantStore $store,
        private TenantContext $context,
    ) {
    }

    // After the firewall (priority 8) has authenticated the user.
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 0)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $securityUser = $this->tokens->getToken()?->getUser();
        if (!$securityUser instanceof SecurityUser) {
            return;
        }

        $user = $this->users->findById($securityUser->id());
        if (!$user instanceof User) {
            return;
        }

        $tenants = $this->memberships->tenantIdsOf($user);
        $chosen = $this->store->get();

        if ($chosen instanceof TenantId && !MembershipCheck::isMember($tenants, $chosen)) {
            $this->store->clear();

            return;
        }

        if (!$chosen instanceof TenantId) {
            if (1 !== \count($tenants)) {
                return;
            }
            $chosen = $tenants[0];
            $this->store->store($chosen);
        }

        $this->context->set($chosen);
    }
}
