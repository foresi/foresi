<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Application\GuestCheck;
use App\Identity\Application\OpenToGuest;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Keeps guests to the pages marked #[OpenToGuest]: every other
 * controller is denied to them, so a new screen is for the staff unless it says
 * otherwise. Roles are membership data, so the firewall does not know them.
 */
final readonly class GuestAccessGuard
{
    /**
     * The code step of the sign-in belongs to the two-factor bundle, whose
     * controller carries no attribute of ours.
     */
    private const array VENDOR_ROUTES = ['2fa_login', '2fa_login_check'];

    public function __construct(private GuestCheck $guest)
    {
    }

    #[AsEventListener(event: KernelEvents::CONTROLLER)]
    public function onController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest() || [] !== $event->getAttributes(OpenToGuest::class)) {
            return;
        }
        if (\in_array($event->getRequest()->attributes->get('_route'), self::VENDOR_ROUTES, true)) {
            return;
        }
        if ($this->guest->isGuest()) {
            throw new AccessDeniedException('Guests see only what is shared with them.');
        }
    }
}
