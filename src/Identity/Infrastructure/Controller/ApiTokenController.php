<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Controller;

use App\Audit\Application\AuditActor;
use App\Identity\Application\ApiTokenRefused;
use App\Identity\Application\ManageApiTokens;
use App\Identity\Application\People;
use App\Identity\Application\Person;
use App\Identity\Application\UserRepository;
use App\Identity\Domain\ApiScope;
use App\Identity\Domain\ApiToken;
use App\Identity\Domain\ApiTokenState;
use App\Identity\Domain\User;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The personal API tokens in the settings: a person lists, issues and revokes
 * their own, an owner sees and revokes those of the other people too. Who may
 * do what is decided by the service; this controller only turns its refusals
 * into answers. The secret of a new token is in the answer to the form that
 * issued it, once, and is never stored, redirected to or cached.
 */
final class ApiTokenController extends AbstractController
{
    public const string ROUTE = 'app_api_tokens';
    public const string NEW_ROUTE = 'app_api_token_new';
    public const string REVOKE_ROUTE = 'app_api_token_revoke';
    public const string TOKEN = 'api_tokens';

    public function __construct(
        private readonly ManageApiTokens $tokens,
        private readonly People $people,
        private readonly UserRepository $users,
        private readonly AuditActor $actor,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/settings/api', name: self::ROUTE, methods: ['GET'])]
    public function index(): Response
    {
        try {
            $mine = $this->tokens->mine();
            $others = $this->othersTokens();
        } catch (ApiTokenRefused $refused) {
            throw $this->denied($refused);
        }
        $people = [];
        if (null !== $others) {
            foreach ($this->people->all() as $person) {
                $people[$person->userId] = $person;
            }
        }

        return $this->render('identity/api/index.html.twig', [
            'canIssue' => $this->hasTwoFactor(),
            'mineColumns' => $this->columns(false),
            'mineRows' => array_map(fn (ApiToken $token): array => $this->row($token, false, null), $mine),
            'othersColumns' => $this->columns(true),
            'othersRows' => null === $others ? null : array_map(fn (ApiToken $token): array => $this->row($token, true, $people[$token->userId()] ?? null), $others),
        ]);
    }

    /**
     * The form in a dialog (the same URL is a page of its own) and, posted,
     * the one answer that shows the secret.
     */
    #[Route('/settings/api/new', name: self::NEW_ROUTE, methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $values = ['name' => '', 'days' => (string) ManageApiTokens::DEFAULT_EXPIRY_DAYS];
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->checkToken($request);
            $values['name'] = trim($request->request->getString('name'));
            $values['days'] = $request->request->getString('days');
            try {
                $issued = $this->tokens->issue($values['name'], [ApiScope::Read], ctype_digit($values['days']) ? (int) $values['days'] : 0);

                return $this->noStore($this->render('identity/api/created.html.twig', [
                    'name' => $issued->token->name(),
                    'secret' => $issued->secret,
                    'expires' => $this->date($issued->token->expiresAt()),
                ]));
            } catch (ApiTokenRefused $refused) {
                $errors[match ($refused->reason) {
                    ApiTokenRefused::NAME => 'name',
                    ApiTokenRefused::EXPIRY => 'days',
                    ApiTokenRefused::TWO_FACTOR_REQUIRED, ApiTokenRefused::SCOPES => 'form',
                    default => throw $this->denied($refused),
                }] = $this->translator->trans('api_tokens.refused.'.$refused->reason);
            }
        }

        return $this->render('identity/api/new.html.twig', [
            'canIssue' => $this->hasTwoFactor(),
            'values' => $values,
            'errors' => $errors,
            'expiries' => array_combine(
                array_map(strval(...), ManageApiTokens::EXPIRY_DAYS),
                array_map(fn (int $days): string => $this->translator->trans('api_tokens.expiry', ['days' => $days]), ManageApiTokens::EXPIRY_DAYS),
            ),
        ], new Response(status: [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route('/settings/api/{id}/revoke', name: self::REVOKE_ROUTE, requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function revoke(string $id, Request $request): RedirectResponse
    {
        $this->checkToken($request);
        try {
            $this->tokens->revoke($id);
        } catch (ApiTokenRefused $refused) {
            throw ApiTokenRefused::UNKNOWN === $refused->reason ? new NotFoundHttpException($refused->getMessage(), $refused) : $this->denied($refused);
        }
        $this->addFlash('success', $this->translator->trans('api_tokens.flash.revoked'));

        return $this->redirectToRoute(self::ROUTE);
    }

    /**
     * @return ?list<ApiToken> the tokens of the other people; null for whoever is not the owner
     *
     * @throws ApiTokenRefused
     */
    private function othersTokens(): ?array
    {
        try {
            $all = $this->tokens->organisation();
        } catch (ApiTokenRefused $refused) {
            if (ApiTokenRefused::NOT_YOURS === $refused->reason) {
                return null;
            }
            throw $refused;
        }
        $me = $this->actor->userId();

        return array_values(array_filter($all, static fn (ApiToken $token): bool => $token->userId() !== $me));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function columns(bool $withPerson): array
    {
        $columns = [['key' => 'name', 'label' => $this->translator->trans('api_tokens.column.name'), 'truncate' => true]];
        if ($withPerson) {
            $columns[] = ['key' => 'person', 'label' => $this->translator->trans('api_tokens.column.person'), 'truncate' => true, 'phone' => false];
            $columns[] = ['key' => 'email', 'label' => $this->translator->trans('api_tokens.column.email'), 'truncate' => true, 'phone' => false];
        }

        return [...$columns,
            ['key' => 'access', 'label' => $this->translator->trans('api_tokens.column.access'), 'phone' => false],
            ['key' => 'token', 'label' => $this->translator->trans('api_tokens.column.token'), 'phone' => false],
            ['key' => 'created', 'label' => $this->translator->trans('api_tokens.column.created'), 'phone' => false],
            ['key' => 'expires', 'label' => $this->translator->trans('api_tokens.column.expires')],
            ['key' => 'lastUsed', 'label' => $this->translator->trans('api_tokens.column.last_used'), 'phone' => false],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(ApiToken $token, bool $withPerson, ?Person $person): array
    {
        $state = $token->state($this->clock->now());
        $expires = $this->date($token->expiresAt());
        $lastUsed = $token->lastUsedAt();
        $cells = ['name' => $token->name()];
        if ($withPerson) {
            $cells['person'] = $person?->name;
            $cells['email'] = $person?->email;
        }

        return [
            'id' => $token->id(),
            'label' => $token->name(),
            'secondary' => '…'.$token->lastFour(),
            'cells' => $cells + [
                'access' => ['badges' => array_map(fn (ApiScope $scope): array => ['label' => $this->translator->trans('api_tokens.scope.'.$scope->value), 'tone' => 'gray'], $token->scopes())],
                'token' => '…'.$token->lastFour(),
                'created' => $this->date($token->createdAt()),
                'expires' => match ($state) {
                    ApiTokenState::Active => $expires,
                    ApiTokenState::Expired => ['text' => $expires, 'flag' => ['label' => $this->translator->trans('api_tokens.state.expired'), 'tone' => 'amber']],
                    ApiTokenState::Revoked => ['text' => $expires, 'flag' => ['label' => $this->translator->trans('api_tokens.state.revoked'), 'tone' => 'red']],
                },
                'lastUsed' => $lastUsed instanceof \DateTimeImmutable ? $lastUsed->format($this->translator->trans('format.datetime')) : $this->translator->trans('api_tokens.never'),
            ],
            'actions' => ApiTokenState::Active === $state ? [
                ['label' => $this->translator->trans('api_tokens.action.revoke'), 'href' => $this->generateUrl(self::REVOKE_ROUTE, ['id' => $token->id()]), 'method' => 'POST', 'confirm' => [
                    'title' => $this->translator->trans('api_tokens.confirm.revoke.title', ['name' => $token->name()]),
                    'text' => $this->translator->trans('api_tokens.confirm.revoke.text'),
                    'accept' => $this->translator->trans('api_tokens.action.revoke'),
                    'tone' => 'danger',
                ]],
            ] : [],
        ];
    }

    private function date(\DateTimeImmutable $date): string
    {
        return $date->format($this->translator->trans('format.date'));
    }

    private function hasTwoFactor(): bool
    {
        $id = $this->actor->userId();
        $user = null === $id ? null : $this->users->findById($id);

        return $user instanceof User && $user->hasTwoFactor();
    }

    private function denied(ApiTokenRefused $refused): AccessDeniedHttpException
    {
        return new AccessDeniedHttpException($refused->getMessage(), $refused);
    }

    /** A secret is shown once: no browser, proxy or Turbo cache keeps the answer. */
    private function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
