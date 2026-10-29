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
use App\Identity\Infrastructure\Controller\TenantChoiceController;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Activates the signed-in user's tenant on every request. Without a valid
 * choice no tenant is set, so tenant-owned data stays unreachable; a user with
 * several organisations is sent to the choice page for page requests.
 */
final readonly class ActiveTenantSubscriber
{
    /** Pages that must stay reachable while the user has not chosen an organisation. */
    private const array EXEMPT_ROUTES = ['app_login', 'app_logout', TenantChoiceController::CHOOSE_ROUTE, TenantChoiceController::SWITCH_ROUTE];

    private const string ASSET_PATHS = '#^/(assets|_profiler|_wdt|build)(/|$)#';

    public function __construct(
        private TokenStorageInterface $tokens,
        private UserRepository $users,
        private MembershipDirectory $memberships,
        private ActiveTenantStore $store,
        private TenantContext $context,
        private UrlGeneratorInterface $urls,
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
            $this->askToChoose($event, \count($tenants));

            return;
        }

        if (!$chosen instanceof TenantId) {
            if (1 !== \count($tenants)) {
                $this->askToChoose($event, \count($tenants));

                return;
            }
            $chosen = $tenants[0];
            $this->store->store($chosen);
        }

        $this->context->set($chosen);
    }

    private function askToChoose(RequestEvent $event, int $organisations): void
    {
        if ($organisations < 2 || !$this->isPageRequest($event->getRequest())) {
            return;
        }

        $event->setResponse(new RedirectResponse($this->urls->generate(TenantChoiceController::CHOOSE_ROUTE)));
    }

    /**
     * HTML pages only: JSON and other API calls are left alone, as are the
     * login, logout and choice pages and the static assets.
     */
    private function isPageRequest(Request $request): bool
    {
        if ('html' !== $request->getPreferredFormat('html')) {
            return false;
        }
        $route = $request->attributes->get('_route');
        if (\is_string($route) && \in_array($route, self::EXEMPT_ROUTES, true)) {
            return false;
        }

        return 1 !== preg_match(self::ASSET_PATHS, $request->getPathInfo());
    }
}
