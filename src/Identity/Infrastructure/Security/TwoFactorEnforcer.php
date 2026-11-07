<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Infrastructure\Controller\InvitationController;
use App\Identity\Infrastructure\Controller\LanguageController;
use App\Identity\Infrastructure\Controller\TwoFactorSetupController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * With APP_REQUIRE_2FA on, a user signed in without two-factor sign-in reaches
 * no page but its enrolment (and signing out) until they have set it up.
 */
final readonly class TwoFactorEnforcer
{
    private const array OPEN_ROUTES = [
        TwoFactorSetupController::SETUP_ROUTE,
        TwoFactorSetupController::RECOVERY_CODES_ROUTE,
        LanguageController::ROUTE,
        // Accepting first; the enrolment follows on the page it leads to.
        InvitationController::ROUTE,
        'app_logout',
    ];

    public function __construct(
        private Security $security,
        private UrlGeneratorInterface $urls,
        #[Autowire(env: 'bool:APP_REQUIRE_2FA')] private bool $required,
    ) {
    }

    // After the firewall (priority 8), before the organisation is chosen (priority 0).
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 4)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$this->required || !$event->isMainRequest() || \in_array($event->getRequest()->attributes->get('_route'), self::OPEN_ROUTES, true)) {
            return;
        }
        $user = $this->security->getUser();
        if (!$user instanceof SecurityUser || $user->hasTwoFactor() || !$this->security->isGranted('IS_AUTHENTICATED_FULLY')) {
            return;
        }
        $event->setResponse(new RedirectResponse($this->urls->generate(TwoFactorSetupController::SETUP_ROUTE)));
    }
}
