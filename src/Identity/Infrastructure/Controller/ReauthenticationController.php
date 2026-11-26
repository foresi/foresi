<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Infrastructure\Controller;

use Foresi\Identity\Application\OpenToGuest;
use Foresi\Identity\Infrastructure\Security\Reauthentication;
use Foresi\Identity\Infrastructure\Security\ReauthenticationFailed;
use Foresi\Identity\Infrastructure\Security\RequiresReauthentication;
use Foresi\Identity\Infrastructure\Security\SecurityUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Confirm it's you" before a sensitive page (GitHub sudo mode): it says why
 * it asks, takes the password, or with two-factor sign-in a code, and goes on
 * to the page that asked. The page it returns to is a path of this site only,
 * so the prompt never sends anyone elsewhere (OWASP: unvalidated redirects).
 * In a dialog the page that asked is shown in the same dialog afterwards.
 */
#[OpenToGuest]
final class ReauthenticationController extends AbstractController
{
    public const string ROUTE = 'app_reauthenticate';
    public const string TOKEN = 'reauthenticate';
    private const string FALLBACK = 'app_dashboard';
    /** Worded for no page in particular, when the address names no known reason. */
    private const string ANY_REASON = 'default';

    public function __construct(
        private readonly Reauthentication $reauthentication,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/account/confirm', name: self::ROUTE, methods: ['GET', 'POST'])]
    public function confirm(Request $request, #[CurrentUser] SecurityUser $me): Response
    {
        $reason = $this->reason($request->query->getString('reason'));
        $target = $this->target($request->query->getString('target'));
        if ($request->isMethod('GET')) {
            return $this->reauthentication->isFresh($request->getSession()) ? $this->redirect($target) : $this->prompt($reason, $target);
        }
        if (!$this->isCsrfTokenValid(self::TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
        try {
            $this->reauthentication->confirm($me, $request->request->getString('secret'), $request->getSession());
        } catch (ReauthenticationFailed $failed) {
            return $this->refused($reason, $target, $failed);
        }

        return $this->redirect($target);
    }

    /**
     * The prompt again, saying why the answer was refused.
     */
    public function refused(string $reason, string $target, ReauthenticationFailed $failed): Response
    {
        return $this->prompt($reason, $target, $this->translator->trans('reauth.error.'.$failed->reason, ['minutes' => $failed->minutes]));
    }

    /**
     * The prompt for the page at $target; the gate shows it in place of a page asked for in a dialog.
     */
    public function prompt(string $reason, string $target, ?string $error = null): Response
    {
        $user = $this->getUser();
        $twoFactor = $user instanceof SecurityUser && $user->hasTwoFactor();

        return $this->render('@Identity/reauthenticate.html.twig', [
            'reason' => $reason,
            'target' => $target,
            'twoFactor' => $twoFactor,
            'error' => $error,
            'minutes' => intdiv(Reauthentication::WINDOW, 60),
            // The enrolment is a page of the sign-in, where the app's navigation leads nowhere yet.
            'layout' => RequiresReauthentication::TWO_FACTOR === $reason ? 'auth' : 'app',
            'cancel' => $this->generateUrl(match ($reason) {
                RequiresReauthentication::TWO_FACTOR => ProfileController::ROUTE,
                RequiresReauthentication::API_TOKEN => ApiTokenController::ROUTE,
                RequiresReauthentication::EMAIL => EmailController::ROUTE,
                default => self::FALLBACK,
            }),
        ], new Response(status: null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    private function reason(string $reason): string
    {
        return \in_array($reason, RequiresReauthentication::REASONS, true) ? $reason : self::ANY_REASON;
    }

    /**
     * A path on this site, never another host: not "//host", not "/\host", no scheme, no control characters.
     */
    private function target(string $target): string
    {
        if (1 === preg_match('#^/(?![/\\\\])[^\x00-\x1f\x7f]*$#', $target) && !str_contains($target, '\\')) {
            return $target;
        }

        return $this->generateUrl(self::FALLBACK);
    }
}
