<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Integration\Ui\Controller;

use Foresi\Identity\Application\OpenToGuest;
use Scheb\TwoFactorBundle\Controller\FormController;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * The sign-in pages of the session firewall, which exists only with Ui
 * (IdentityBundle): the password, signing out and the code step after the
 * password.
 */
#[OpenToGuest]
final class LoginController extends AbstractController
{
    public const string TWO_FACTOR_ROUTE = '2fa_login';
    public const string TWO_FACTOR_CHECK_ROUTE = '2fa_login_check';

    public function __construct(
        #[Autowire(service: 'scheb_two_factor.form_controller')]
        private readonly FormController $twoFactorForm,
    ) {
    }

    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authentication): Response
    {
        return $this->render('@Identity/login.html.twig', [
            'lastUsername' => $authentication->getLastUsername(),
            'error' => $authentication->getLastAuthenticationError(),
        ]);
    }

    /**
     * POST only, with the CSRF token of the user menu form: a link or an image
     * tag on another site can never sign the user out.
     */
    #[Route('/logout', name: 'app_logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new \LogicException('The firewall intercepts this route.');
    }

    /**
     * The code step after the password, rendered by the two-factor bundle with
     * the template IdentityBundle gives it.
     */
    #[Route('/login/code', name: self::TWO_FACTOR_ROUTE)]
    public function twoFactor(Request $request): Response
    {
        return $this->twoFactorForm->form($request);
    }

    /**
     * The firewall checks the code here.
     */
    #[Route('/login/code/check', name: self::TWO_FACTOR_CHECK_ROUTE, methods: ['POST'])]
    public function twoFactorCheck(): never
    {
        throw new \LogicException('The firewall intercepts this route.');
    }
}
