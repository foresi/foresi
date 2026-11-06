<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Controller;

use App\Identity\Application\UserRepository;
use App\Identity\Infrastructure\Security\RecoveryCodes;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Identity\Infrastructure\Security\TotpQrCode;
use App\Shared\Application\Sealer;
use App\Shared\Application\SealerUnavailable;
use Psr\Log\LoggerInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Enrolment in two-factor sign-in: scan the QR code (or type the key) into an
 * authenticator app, confirm with one code, then note the recovery codes, shown
 * this once. The secret waits sealed in the session until the code confirms it.
 */
final class TwoFactorSetupController extends AbstractController
{
    public const string SETUP_ROUTE = 'app_two_factor_setup';
    public const string RECOVERY_CODES_ROUTE = 'app_two_factor_recovery_codes';
    public const string TOKEN_ID = 'two_factor_setup';
    private const string PENDING_SECRET = 'identity.two_factor.pending_secret';
    private const string NEW_RECOVERY_CODES = 'identity.two_factor.recovery_codes';

    public function __construct(
        private readonly UserRepository $users,
        private readonly Sealer $sealer,
        private readonly TotpAuthenticatorInterface $totp,
        private readonly TotpQrCode $qrCode,
        private readonly RecoveryCodes $recoveryCodes,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/account/two-factor', name: self::SETUP_ROUTE, methods: ['GET', 'POST'])]
    public function setup(Request $request, #[CurrentUser] SecurityUser $securityUser): Response
    {
        if ($securityUser->hasTwoFactor()) {
            return $this->redirectToRoute('app_dashboard');
        }
        if (!$this->sealer->isConfigured()) {
            return $this->render('identity/two_factor_setup.html.twig', ['unavailable' => true], new Response(status: Response::HTTP_SERVICE_UNAVAILABLE));
        }
        $session = $request->getSession();
        $secret = $this->pendingSecret($session->get(self::PENDING_SECRET));
        if (null === $secret) {
            $secret = $this->totp->generateSecret();
            $session->set(self::PENDING_SECRET, $this->sealer->seal($secret));
        }
        $candidate = $securityUser->withTotpSecret($secret);

        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid(self::TOKEN_ID, $request->request->getString('_token'))) {
                throw new AccessDeniedHttpException('Invalid CSRF token.');
            }
            if ($this->totp->checkCode($candidate, $request->request->getString('code'))) {
                $this->enrol($securityUser, $secret, $session);

                return $this->redirectToRoute(self::RECOVERY_CODES_ROUTE);
            }
            $error = 'two_factor.setup.code_invalid';
        }

        return $this->render('identity/two_factor_setup.html.twig', [
            'unavailable' => false,
            'qrCode' => $this->qrCode->svg($this->totp->getQRContent($candidate)),
            'manualKey' => implode(' ', str_split($secret, 4)),
            'error' => $error,
        ], new Response(status: null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /**
     * The codes, once: the session forgets them as soon as they are shown.
     */
    #[Route('/account/two-factor/recovery-codes', name: self::RECOVERY_CODES_ROUTE, methods: ['GET'])]
    public function recoveryCodes(Request $request): Response
    {
        $codes = $request->getSession()->remove(self::NEW_RECOVERY_CODES);
        if (!\is_array($codes) || [] === $codes) {
            return $this->redirectToRoute('app_dashboard');
        }

        $response = $this->render('identity/two_factor_recovery_codes.html.twig', ['codes' => array_values(array_filter($codes, \is_string(...)))]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    private function enrol(SecurityUser $securityUser, #[\SensitiveParameter] string $secret, SessionInterface $session): void
    {
        $user = $this->users->findById($securityUser->id()) ?? throw new AccessDeniedHttpException('The signed-in user no longer exists.');
        $codes = $this->recoveryCodes->generate();
        $user->enrolTwoFactor($this->sealer->seal($secret), array_map($this->recoveryCodes->hash(...), $codes));
        $this->users->save($user);
        $session->remove(self::PENDING_SECRET);
        $session->set(self::NEW_RECOVERY_CODES, $codes);
        $this->logger->notice('Two-factor sign-in was set up.', ['user' => $user->id()]);
    }

    private function pendingSecret(mixed $sealed): ?string
    {
        if (!\is_string($sealed)) {
            return null;
        }
        try {
            return $this->sealer->open($sealed);
        } catch (SealerUnavailable) {
            // A rotated key: start the enrolment over with a fresh secret.
            return null;
        }
    }
}
