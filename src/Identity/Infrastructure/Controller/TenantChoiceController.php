<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Controller;

use App\Identity\Application\MembershipDirectory;
use App\Identity\Application\SwitchTenant;
use App\Identity\Application\UserRepository;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Tenancy\Domain\TenantId;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Lists the user's organisations and switches between them. Switching is a
 * POST with a CSRF token; an organisation the user does not belong to looks
 * exactly like one that does not exist.
 */
final class TenantChoiceController extends AbstractController
{
    public const string CHOOSE_ROUTE = 'app_organisation_choose';
    public const string SWITCH_ROUTE = 'app_organisation_switch';
    public const string TOKEN_ID = 'organisation_switch';

    public function __construct(
        private readonly MembershipDirectory $memberships,
        private readonly UserRepository $users,
        private readonly SwitchTenant $switchTenant,
    ) {
    }

    #[Route('/organisation/choose', name: self::CHOOSE_ROUTE, methods: ['GET'])]
    public function choose(#[CurrentUser] SecurityUser $securityUser): Response
    {
        return $this->render('identity/organisation_choose.html.twig', [
            'organisations' => $this->memberships->organisationsOf($securityUser->id()),
        ]);
    }

    #[Route('/organisation/switch/{tenantId}', name: self::SWITCH_ROUTE, methods: ['POST'])]
    public function switch(string $tenantId, Request $request, #[CurrentUser] SecurityUser $securityUser): RedirectResponse
    {
        // The token check does not depend on the organisation, so it reveals nothing about it.
        if (!$this->isCsrfTokenValid(self::TOKEN_ID, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }

        try {
            $tenant = TenantId::fromString($tenantId);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException();
        }
        $user = $this->users->findById($securityUser->id());
        if (!$user instanceof User) {
            throw new NotFoundHttpException();
        }

        try {
            ($this->switchTenant)($user, $tenant);
        } catch (\DomainException) {
            throw new NotFoundHttpException();
        }

        return $this->redirectToRoute('app_dashboard');
    }
}
