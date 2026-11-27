<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Integration\Ui\Controller;

use Foresi\Identity\Application\ChangePassword;
use Foresi\Identity\Application\OpenToGuest;
use Foresi\Identity\Application\PasswordRefused;
use Foresi\Identity\Domain\User;
use Foresi\Identity\Infrastructure\Security\SecurityUser;
use Foresi\Identity\Infrastructure\Security\UserProvider;
use Foresi\Identity\Integration\Ui\Security\Reauthentication;
use Foresi\Identity\Integration\Ui\Security\ReauthenticationFailed;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The person's password, in the security part of their account. It changes in
 * a dialog from the page header: the current password, which is also the check
 * that it is them, and the new one once, with Show passwords instead of a second
 * field (Google Account; NIST SP 800-63B: no composition rules, paste allowed).
 *
 * Afterwards this browser gets a new session id and stays signed in, while every
 * other session ends on its next request: the session keeps a checksum of the
 * password hash, which no longer matches. Browsers trusted to skip the code ask
 * for it again, and the person gets a notice by e-mail.
 */
#[OpenToGuest]
final class PasswordController extends AbstractController
{
    public const string ROUTE = 'app_account_password';
    public const string CHANGE_ROUTE = 'app_account_password_change';
    public const string TOKEN = 'password';

    public function __construct(
        private readonly ChangePassword $passwords,
        private readonly Reauthentication $reauthentication,
        private readonly UserProvider $userProvider,
        private readonly TokenStorageInterface $tokens,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/account/password', name: self::ROUTE, methods: ['GET'])]
    public function index(#[CurrentUser] SecurityUser $me): Response
    {
        return $this->render('@Identity/password/index.html.twig', ['email' => $me->getUserIdentifier(), 'twoFactor' => $me->hasTwoFactor()]);
    }

    /**
     * The change in a dialog; the same URL is a page of its own.
     */
    #[Route('/account/password/change', name: self::CHANGE_ROUTE, methods: ['GET', 'POST'])]
    public function change(Request $request, #[CurrentUser] SecurityUser $me): Response
    {
        $errors = [];
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid(self::TOKEN, $request->request->getString('_token'))) {
                throw new AccessDeniedHttpException('Invalid CSRF token.');
            }
            try {
                // The current password is a guess like any other: it counts against the same attempts.
                $this->reauthentication->guardAttempt($me->id());
                $this->passwords->change($me->id(), $request->request->getString('currentPassword'), $request->request->getString('newPassword'));
                $this->reauthentication->succeededAttempt($me->id());
                $this->keepSignedIn($request, $me);
                $this->addFlash('success', $this->translator->trans('password.flash.changed'));

                return $this->redirectToRoute(self::ROUTE);
            } catch (ReauthenticationFailed $failed) {
                $errors[PasswordRefused::CURRENT_FIELD] = $this->translator->trans('reauth.error.'.$failed->reason, ['minutes' => $failed->minutes]);
            } catch (PasswordRefused $refused) {
                if (PasswordRefused::CURRENT_WRONG === $refused->reason()) {
                    $this->reauthentication->failedAttempt($me->id());
                }
                $errors[(string) $refused->field()] = $this->translator->trans('password.error.'.$refused->reason(), ['min' => User::MIN_PASSWORD_LENGTH]);
            }
        }

        return $this->render('@Identity/password/change.html.twig', [
            'email' => $me->getUserIdentifier(),
            'min' => User::MIN_PASSWORD_LENGTH,
            'errors' => $errors,
        ], new Response(status: [] === $errors ? 200 : 422));
    }

    /**
     * A new session id, so a session id seen before the change is worth nothing (OWASP
     * session management), and the token's user reloaded with the new hash, so the
     * checksum of this session matches and only the other sessions end. The change
     * itself proved it is the person, so the window for sensitive pages opens.
     */
    private function keepSignedIn(Request $request, SecurityUser $me): void
    {
        $session = $request->getSession();
        $session->migrate(true);
        $this->tokens->getToken()?->setUser($this->userProvider->refreshUser($me));
        $this->reauthentication->markFresh($session);
    }
}
