<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Infrastructure\Controller;

use Foresi\Audit\Application\AuditActor;
use Foresi\Identity\Application\ApiTokenRefused;
use Foresi\Identity\Application\ManageApiTokens;
use Foresi\Identity\Application\People;
use Foresi\Identity\Application\Person;
use Foresi\Identity\Application\UserRepository;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Identity\Domain\ApiToken;
use Foresi\Identity\Domain\ApiTokenState;
use Foresi\Identity\Domain\User;
use Foresi\Shared\Application\Api\ApiResources;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
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
    public const string CONNECT_ROUTE = 'app_api_connect';
    /** The MCP server over Streamable HTTP (config/packages/mcp.yaml). */
    private const string MCP_ROUTE = '_mcp_endpoint_default';
    public const string TOKEN = 'api_tokens';

    public function __construct(
        private readonly ManageApiTokens $tokens,
        private readonly People $people,
        private readonly UserRepository $users,
        private readonly AuditActor $actor,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
        private readonly ApiResources $resources,
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

        return $this->render('@Identity/api/index.html.twig', [
            'canIssue' => $this->hasTwoFactor(),
            'mineColumns' => $this->columns(false),
            'mineRows' => array_map(fn (ApiToken $token): array => $this->row($token, false, null), $mine),
            'othersColumns' => $this->columns(true),
            'othersRows' => null === $others ? null : array_map(fn (ApiToken $token): array => $this->row($token, true, $people[$token->userId()] ?? null), $others),
        ]);
    }

    /**
     * How to connect Claude and other MCP clients: the server's address, a copy-ready
     * setup for each client, a first prompt to check it and what the scopes of a token mean.
     */
    #[Route('/settings/api/connect', name: self::CONNECT_ROUTE, methods: ['GET'])]
    public function connect(): Response
    {
        // Who may see the tokens may see how to use them; the service decides.
        try {
            $this->tokens->mine();
        } catch (ApiTokenRefused $refused) {
            throw $this->denied($refused);
        }

        return $this->render('@Identity/api/connect.html.twig', [
            'canIssue' => $this->hasTwoFactor(),
            'server' => $this->mcpServer(),
            'setup' => $this->generateUrl(McpSetupController::ROUTE, [], UrlGeneratorInterface::ABSOLUTE_URL),
            'scopes' => $this->offeredScopes(),
        ]);
    }

    /**
     * The form in a dialog (the same URL is a page of its own) and, posted,
     * the one answer that shows the secret.
     */
    #[Route('/settings/api/new', name: self::NEW_ROUTE, methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $values = ['name' => '', 'days' => (string) ManageApiTokens::DEFAULT_EXPIRY_DAYS, 'scopes' => [ApiScope::READ]];
        $errors = [];
        $offered = $this->offeredScopes();
        if ($request->isMethod('POST')) {
            $this->checkToken($request);
            $values['name'] = trim($request->request->getString('name'));
            $values['days'] = $request->request->getString('days');
            // Only what the form offers counts; anything else is dropped, and none at all is refused.
            $values['scopes'] = array_values(array_intersect(array_keys($offered), array_filter($request->request->all('scopes'), \is_string(...))));
            try {
                $issued = $this->tokens->issue($values['name'], array_map(ApiScope::from(...), $values['scopes']), ctype_digit($values['days']) ? (int) $values['days'] : 0);

                return $this->noStore($this->render('@Identity/api/created.html.twig', [
                    'name' => $issued->token->name(),
                    'secret' => $issued->secret,
                    'expires' => $this->date($issued->token->expiresAt()),
                    'server' => $this->mcpServer(),
                ]));
            } catch (ApiTokenRefused $refused) {
                $errors[match ($refused->reason) {
                    ApiTokenRefused::NAME => 'name',
                    ApiTokenRefused::EXPIRY => 'days',
                    ApiTokenRefused::SCOPES => 'scopes',
                    ApiTokenRefused::TWO_FACTOR_REQUIRED => 'form',
                    default => throw $this->denied($refused),
                }] = $this->translator->trans('api_tokens.refused.'.$refused->reason);
            }
        }

        return $this->render('@Identity/api/new.html.twig', [
            'canIssue' => $this->hasTwoFactor(),
            'values' => $values,
            'errors' => $errors,
            'scopes' => $offered,
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
     * Everything a token can be given: all reads, all writes, then reading or
     * changing each resource of the installed modules.
     *
     * @return array<string, string> scope => label
     */
    private function offeredScopes(): array
    {
        $offered = [ApiScope::READ => $this->scopeLabel(ApiScope::read()), ApiScope::WRITE => $this->scopeLabel(ApiScope::write())];
        foreach ($this->resources->declared() as $resource) {
            foreach ([false, true] as $write) {
                $scope = ApiScope::of($resource, $write);
                $offered[$scope->value] = $this->scopeLabel($scope);
            }
        }

        return $offered;
    }

    private function scopeLabel(ApiScope $scope): string
    {
        $resource = $scope->resource();
        if (null === $resource) {
            return $this->translator->trans('api_tokens.scope.'.$scope->value);
        }
        $name = $this->translator->trans('api_tokens.resource.'.$resource);

        return $this->translator->trans($scope->writes() ? 'api_tokens.scope.resource_write' : 'api_tokens.scope.resource_read', ['resource' => $name]);
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
                'access' => ['badges' => array_map(fn (ApiScope $scope): array => ['label' => $this->scopeLabel($scope), 'tone' => 'gray'], $token->scopes())],
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

    private function mcpServer(): string
    {
        return $this->generateUrl(self::MCP_ROUTE, [], UrlGeneratorInterface::ABSOLUTE_URL);
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
