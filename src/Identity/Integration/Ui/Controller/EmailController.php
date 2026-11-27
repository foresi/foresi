<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Integration\Ui\Controller;

use Foresi\Identity\Application\ChangeEmail;
use Foresi\Identity\Application\EmailChangeRefused;
use Foresi\Identity\Application\OpenToGuest;
use Foresi\Identity\Application\UserRepository;
use Foresi\Identity\Domain\EmailChangeToken;
use Foresi\Identity\Domain\User;
use Foresi\Identity\Infrastructure\Mail\SystemEmailChangeMailer;
use Foresi\Identity\Infrastructure\Security\SecurityUser;
use Foresi\Identity\Infrastructure\Security\UserProvider;
use Foresi\Identity\Integration\Ui\Security\RequiresReauthentication;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Shared\Infrastructure\Security\PublicRoute;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The person's sign-in address, in the security part of their account. Change
 * e-mail opens a dialog from the page header once they have confirmed it is
 * them; a link goes to the new address and the page shows the change waiting,
 * with Cancel, until the link is opened (OWASP, Pipedrive). The link may be
 * opened in any browser: it proves the new mailbox is theirs. Afterwards the
 * browser that opened it stays signed in, if it was theirs, and every other
 * session ends: each keeps the old address and the old session version.
 */
#[OpenToGuest]
final class EmailController extends AbstractController
{
    public const string ROUTE = 'app_account_email';
    public const string CHANGE_ROUTE = 'app_account_email_change';
    public const string CANCEL_ROUTE = 'app_account_email_cancel';
    public const string CONFIRM_ROUTE = SystemEmailChangeMailer::CONFIRM_ROUTE;
    public const string TOKEN = 'email';
    public const string CONFIRM_TOKEN = 'email_confirm';

    public function __construct(
        private readonly ChangeEmail $emails,
        private readonly UserRepository $users,
        private readonly UserProvider $userProvider,
        private readonly TokenStorageInterface $tokens,
        private readonly Security $security,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
        private readonly InstanceClock $instanceClock,
        #[Autowire(service: 'limiter.email_change')]
        private readonly RateLimiterFactoryInterface $limiter,
    ) {
    }

    #[Route('/account/email', name: self::ROUTE, methods: ['GET'])]
    public function index(Request $request, #[CurrentUser] SecurityUser $me): Response
    {
        $user = $this->users->findById($me->id()) ?? throw $this->createNotFoundException();
        $pending = $user->pendingEmail($this->clock->now());
        $expiresAt = $user->pendingEmailExpiresAt();

        return $this->render('@Identity/email/index.html.twig', [
            'email' => $user->email(),
            'pending' => $pending,
            'expiresAt' => null === $pending || !$expiresAt instanceof \DateTimeImmutable ? null : new \IntlDateFormatter($request->getLocale(), \IntlDateFormatter::MEDIUM, \IntlDateFormatter::SHORT, $this->instanceClock->timezone())->format($expiresAt),
        ]);
    }

    /**
     * The new address in a dialog; the same URL is a page of its own.
     */
    #[Route('/account/email/change', name: self::CHANGE_ROUTE, methods: ['GET', 'POST'])]
    #[RequiresReauthentication(RequiresReauthentication::EMAIL)]
    public function change(Request $request, #[CurrentUser] SecurityUser $me): Response
    {
        $value = '';
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid(self::TOKEN, $request->request->getString('_token'))) {
                throw new AccessDeniedHttpException('Invalid CSRF token.');
            }
            $value = $request->request->getString(EmailChangeRefused::FIELD);
            // Every start sends a mail to an address of the person's choosing, so a few an hour.
            $limit = $this->limiter->create($me->id())->consume();
            if (!$limit->isAccepted()) {
                $error = $this->translator->trans('email.error.throttled', ['minutes' => (int) ceil(($limit->getRetryAfter()->getTimestamp() - $this->clock->now()->getTimestamp()) / 60)]);
            } else {
                try {
                    $this->emails->start($me->id(), $value);
                    $this->addFlash('success', $this->translator->trans('email.flash.sent', ['email' => strtolower(trim($value))]));

                    return $this->redirectToRoute(self::ROUTE);
                } catch (EmailChangeRefused $refused) {
                    $error = $this->translator->trans('email.error.'.$refused->reason());
                }
            }
        }

        return $this->render('@Identity/email/change.html.twig', [
            'email' => $me->getUserIdentifier(),
            'value' => $value,
            'error' => $error,
        ], new Response(status: null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route('/account/email/cancel', name: self::CANCEL_ROUTE, methods: ['POST'])]
    public function cancel(Request $request, #[CurrentUser] SecurityUser $me): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        if (!$this->isCsrfTokenValid(self::TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
        $this->emails->cancel($me->id());
        $this->addFlash('success', $this->translator->trans('email.flash.cancelled'));

        return $this->redirectToRoute(self::ROUTE);
    }

    /**
     * The link from the mail: a page that asks to confirm, so a mail scanner that
     * opens every link changes nothing, and the change on Confirm.
     */
    #[Route('/account/email/confirm/{token}', name: self::CONFIRM_ROUTE, requirements: ['token' => EmailChangeToken::PATTERN], defaults: [PublicRoute::DEFAULT => true], methods: ['GET', 'POST'])]
    public function confirm(string $token, Request $request): Response
    {
        $user = $this->emails->open($token);
        if (!$user instanceof User) {
            return $this->page('unknown', null, Response::HTTP_NOT_FOUND);
        }
        $signedIn = $this->security->getUser();
        $mine = $signedIn instanceof SecurityUser && $signedIn->id() === $user->id();
        if (!$signedIn instanceof SecurityUser) {
            // Read in the person's language when no session says which.
            $request->setLocale($user->locale());
            if ($this->translator instanceof LocaleAwareInterface) {
                $this->translator->setLocale($user->locale());
            }
        }
        if (!$request->isMethod('POST')) {
            return $this->page('confirm', $user);
        }
        if (!$this->isCsrfTokenValid(self::CONFIRM_TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
        $newEmail = (string) $user->pendingEmail($this->clock->now());
        try {
            $this->emails->confirm($token);
        } catch (EmailChangeRefused $refused) {
            return EmailChangeRefused::TAKEN === $refused->reason()
                ? $this->page('taken', $user, Response::HTTP_CONFLICT, ['newEmail' => $newEmail])
                : $this->page('unknown', null, Response::HTTP_NOT_FOUND);
        }
        if (!$mine) {
            return $this->page('done', null, Response::HTTP_OK, ['newEmail' => $newEmail]);
        }
        $this->keepSignedIn($request, $newEmail);
        $this->addFlash('success', $this->translator->trans('email.flash.changed', ['email' => $newEmail]));

        return $this->redirectToRoute(self::ROUTE);
    }

    /**
     * A new session id, and the token's user loaded by the new address with the new
     * session version, so this session matches it while every other one does not.
     */
    private function keepSignedIn(Request $request, string $newEmail): void
    {
        $request->getSession()->migrate(true);
        $this->tokens->getToken()?->setUser($this->userProvider->loadUserByIdentifier($newEmail));
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function page(string $step, ?User $user, int $status = Response::HTTP_OK, array $extra = []): Response
    {
        return $this->render('@Identity/email/confirm.html.twig', $extra + [
            'step' => $step,
            'email' => $user?->email(),
            'newEmail' => $user?->pendingEmail($this->clock->now()),
        ], new Response(status: $status));
    }
}
