<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Controller;

use App\Identity\Application\AcceptInvitation;
use App\Identity\Application\ActiveTenantStore;
use App\Identity\Application\InvitationRefused;
use App\Identity\Application\OpenInvitation;
use App\Identity\Application\OpenToExternal;
use App\Identity\Domain\InvitationState;
use App\Identity\Domain\InvitationToken;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Security\SecurityUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Util\TargetPathTrait;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The page an invitation link opens. A new person chooses a name and a password
 * and is signed in; someone with an account signs in first and then accepts. An
 * unknown, expired, revoked or used link gets a page that says so. The two-factor
 * enrolment follows as on every sign-in.
 */
#[OpenToExternal]
final class InvitationController extends AbstractController
{
    use TargetPathTrait;

    public const string ROUTE = 'app_invitation';
    public const string TOKEN = 'invitation';
    private const int MAX_NAME = 255;

    public function __construct(
        private readonly AcceptInvitation $accepting,
        private readonly ActiveTenantStore $store,
        private readonly Security $security,
        private readonly PasswordHasherFactoryInterface $hashers,
        private readonly TranslatorInterface $translator,
        #[Autowire(service: 'limiter.invitation_link')]
        private readonly RateLimiterFactoryInterface $limiter,
    ) {
    }

    #[Route('/invitation/{token}', name: self::ROUTE, requirements: ['token' => InvitationToken::PATTERN], methods: ['GET', 'POST'])]
    public function __invoke(string $token, Request $request): Response
    {
        if (!$this->limiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException();
        }
        $open = $this->accepting->open($token);
        if (!$open instanceof OpenInvitation) {
            return $this->page('unknown', null, [], Response::HTTP_NOT_FOUND);
        }
        $signedIn = $this->security->getUser();
        if (!$signedIn instanceof SecurityUser) {
            // Someone without an account here reads the page in the language the owner chose for them.
            $request->setLocale($open->invitation->locale());
            if ($this->translator instanceof LocaleAwareInterface) {
                $this->translator->setLocale($open->invitation->locale());
            }
        }
        if (InvitationState::Pending !== $open->state) {
            return $this->page($open->state->value, $open, [], Response::HTTP_GONE);
        }
        $post = $request->isMethod('POST');
        if ($post && !$this->isCsrfTokenValid(self::TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }

        if ($signedIn instanceof SecurityUser) {
            if ($signedIn->getUserIdentifier() !== $open->invitation->email()) {
                return $this->page('other_user', $open, ['signedInAs' => $signedIn->getUserIdentifier()], Response::HTTP_CONFLICT);
            }
            if (!$post) {
                return $this->page('accept', $open);
            }

            return $this->acceptAsUser($token, $signedIn, $open);
        }
        if ($open->hasAccount) {
            // Back here once signed in.
            $this->saveTargetPath($request->getSession(), 'main', $request->getUri());

            return $this->page('sign_in', $open);
        }

        return $this->newAccount($token, $request, $open, $post);
    }

    private function acceptAsUser(string $token, SecurityUser $user, OpenInvitation $open): Response
    {
        try {
            $tenant = $this->accepting->asUser($token, $user->id());
        } catch (InvitationRefused $refused) {
            return $this->page('member' === $refused->reason ? 'member' : 'unknown', $open, [], Response::HTTP_CONFLICT);
        }
        $this->store->store($tenant);

        return $this->redirectToRoute('app_dashboard');
    }

    private function newAccount(string $token, Request $request, OpenInvitation $open, bool $post): Response
    {
        $values = ['name' => $open->invitation->displayName()];
        $errors = [];
        if ($post) {
            $values['name'] = mb_substr(trim($request->request->getString('name')), 0, self::MAX_NAME);
            $password = $request->request->getString('password');
            if ('' === $values['name']) {
                $errors['name'] = $this->translator->trans('invitation.error.name');
            }
            if (User::MIN_PASSWORD_LENGTH > mb_strlen($password)) {
                $errors['password'] = $this->translator->trans('invitation.error.password_short', ['min' => User::MIN_PASSWORD_LENGTH]);
            } elseif ($password !== $request->request->getString('password_repeat')) {
                $errors['password_repeat'] = $this->translator->trans('invitation.error.password_mismatch');
            }
            if ([] === $errors) {
                try {
                    $user = $this->accepting->asNewUser($token, $values['name'], $this->hashers->getPasswordHasher(SecurityUser::class)->hash($password));
                } catch (InvitationRefused) {
                    return $this->page('unknown', $open, [], Response::HTTP_CONFLICT);
                }
                $this->security->login(SecurityUser::fromUser($user), 'form_login', 'main');

                return $this->redirectToRoute('app_dashboard');
            }
        }

        return $this->page('new_account', $open, ['values' => $values, 'errors' => $errors], [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function page(string $step, ?OpenInvitation $open, array $extra = [], int $status = Response::HTTP_OK): Response
    {
        return $this->render('identity/invitation.html.twig', [
            'step' => $step,
            'invitation' => $open?->invitation,
            'organisation' => $open?->organisation,
            'minPassword' => User::MIN_PASSWORD_LENGTH,
        ] + $extra, new Response(status: $status));
    }
}
