<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Application\UserRepository;
use App\Identity\Domain\User;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Serves every page in the signed-in user's language. Anonymous requests keep
 * the application default (Czech).
 */
final readonly class UserLocaleSubscriber
{
    public function __construct(
        private TokenStorageInterface $tokens,
        private UserRepository $users,
        private LocaleSwitcher $locales,
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

        $event->getRequest()->setLocale($user->locale());
        $this->locales->setLocale($user->locale());
    }
}
