<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Infrastructure\Security;

use Foresi\Identity\Infrastructure\Controller\ReauthenticationController;
use Foresi\Ui\Contract\Dialog\DialogRequest;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Holds a page marked #[RequiresReauthentication] until the person has
 * confirmed it is them within the window. A page request goes to the prompt and
 * comes back after it; a dialog shows the prompt in its place, since a redirect
 * would take the person out of the dialog. A completed sign-in counts as a
 * confirmation, as on GitHub: whoever has just given the password and the code
 * is not asked for them again.
 */
final readonly class ReauthenticationGate
{
    public function __construct(
        private Reauthentication $reauthentication,
        private Security $security,
        private UrlGeneratorInterface $urls,
        private ReauthenticationController $prompt,
    ) {
    }

    #[AsEventListener(event: KernelEvents::CONTROLLER_ARGUMENTS)]
    public function onControllerArguments(ControllerArgumentsEvent $event): void
    {
        $attributes = $event->getAttributes(RequiresReauthentication::class);
        if ([] === $attributes) {
            return;
        }
        $request = $event->getRequest();
        if (!$this->security->getUser() instanceof SecurityUser || $this->reauthentication->isFresh($request->getSession())) {
            return;
        }
        if (!$event->isMainRequest()) {
            throw new AccessDeniedHttpException('The person has not confirmed it is them.');
        }
        $reason = $attributes[0]->reason;
        $target = $request->getRequestUri();
        if (DialogRequest::is($request)) {
            $event->setController(fn () => $this->prompt->prompt($reason, $target), []);
            $event->setArguments([]);

            return;
        }
        $event->setController(fn (): RedirectResponse => new RedirectResponse($this->urls->generate(ReauthenticationController::ROUTE, ['reason' => $reason, 'target' => $target])), []);
        $event->setArguments([]);
    }

    #[AsEventListener(event: LoginSuccessEvent::class, dispatcher: 'security.event_dispatcher.main')]
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        // The password alone is half a sign-in while the code is still to come.
        if ($event->getAuthenticatedToken() instanceof TwoFactorTokenInterface || !$event->getRequest()->hasSession()) {
            return;
        }
        $this->reauthentication->markFresh($event->getRequest()->getSession());
    }
}
