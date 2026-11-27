<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Integration\Ui\Controller;

use Foresi\Audit\Application\AuditActor;
use Foresi\Identity\Application\ApiTokenRefused;
use Foresi\Identity\Application\ManageApiTokens;
use Foresi\Identity\Application\OAuth\OAuthStore;
use Foresi\Identity\Application\OpenToGuest;
use Foresi\Identity\Application\People;
use Foresi\Identity\Application\Person;
use Foresi\Identity\Application\UserRepository;
use Foresi\Identity\Application\View\ApiTokenLists;
use Foresi\Identity\Contract\StaffCheck;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Identity\Domain\ApiToken;
use Foresi\Identity\Domain\ApiTokenState;
use Foresi\Identity\Domain\OAuth\OAuthClient;
use Foresi\Identity\Domain\User;
use Foresi\Identity\Integration\Ui\Column\ApiTokenRow;
use Foresi\Identity\Integration\Ui\Security\RequiresReauthentication;
use Foresi\Shared\Application\View\ListSlot;
use Foresi\Shared\Application\View\ListTables;
use Foresi\Shared\Infrastructure\View\FilterSheet;
use Foresi\Ui\Contract\WorkingState;
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
 * their own, an owner sees and revokes those of the other people too. The OAuth
 * clients a person connected (ADR 0036) are listed apart and disconnected with
 * the same revoke. A guest has no tokens and no settings: their own page,
 * Connected apps, lists and disconnects the clients they connected (ADR 0041).
 * Who may do what is decided by the service; this controller only turns its refusals
 * into answers. The secret of a new token is in the answer to the form that
 * issued it, once, and is never stored, redirected to or cached. Each table is a
 * list with views, columns and widths of its own (list-pages.md, rules 8 and 9).
 */
final class ApiTokenController extends AbstractController
{
    public const string ROUTE = 'app_api_tokens';
    public const string NEW_ROUTE = 'app_api_token_new';
    public const string REVOKE_ROUTE = 'app_api_token_revoke';
    public const string CONNECT_ROUTE = 'app_api_connect';
    public const string GUEST_ROUTE = 'app_connected_apps';
    public const string GUEST_DISCONNECT_ROUTE = 'app_connected_app_disconnect';
    /** The MCP server over Streamable HTTP (config/packages/mcp.yaml). */
    private const string MCP_ROUTE = '_mcp_endpoint_default';
    public const string TOKEN = 'api_tokens';
    /** The slots of the tables after the first, the connected apps, in the page's address (ListSlot). */
    public const string TOKENS_SLOT = 'tokens';
    public const string OTHERS_SLOT = 'others';
    public const string OTHERS_CONNECTIONS_SLOT = 'others_apps';

    /**
     * @param ListTables<WorkingState> $listTable
     */
    public function __construct(
        private readonly ManageApiTokens $tokens,
        private readonly People $people,
        private readonly UserRepository $users,
        private readonly AuditActor $actor,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
        private readonly ApiScopeOptions $scopeOptions,
        private readonly OAuthStore $oauth,
        private readonly StaffCheck $staff,
        private readonly ApiTokenLists $lists,
        private readonly ListTables $listTable,
        private readonly FilterSheet $filterSheet,
    ) {
    }

    #[Route('/settings/api', name: self::ROUTE, methods: ['GET'])]
    public function index(Request $request): Response
    {
        try {
            $mine = $this->tokens->mine();
            $others = $this->othersTokens();
            $myConnections = $this->live($this->tokens->myConnections());
            $othersConnections = null === $others ? null : $this->othersConnections();
        } catch (ApiTokenRefused $refused) {
            throw $this->denied($refused);
        }
        $people = [];
        if (null !== $others || null !== $othersConnections) {
            foreach ($this->people->all() as $person) {
                $people[$person->userId] = $person;
            }
        }
        $page = $request->query->all();
        // Four tables on one page (list-pages.md, rule 9): the connected apps keep the bare keys, the others theirs under a slot.
        $slots = [
            ApiTokenLists::CONNECTIONS => ListSlot::first($page, self::TOKENS_SLOT, self::OTHERS_SLOT, self::OTHERS_CONNECTIONS_SLOT),
            ApiTokenLists::TOKENS => ListSlot::named(self::TOKENS_SLOT, $page),
            ApiTokenLists::OTHERS_TOKENS => ListSlot::named(self::OTHERS_SLOT, $page),
            ApiTokenLists::OTHERS_CONNECTIONS => ListSlot::named(self::OTHERS_CONNECTIONS_SLOT, $page),
        ];
        foreach ($slots as $key => $slot) {
            // The builder's rows (spec D11) become the table's working filter, in its part of the address.
            $built = $this->listTable->built($slot, $this->lists->list($key));
            if (null !== $built) {
                return $this->redirectToRoute(self::ROUTE, $built, Response::HTTP_SEE_OTHER);
            }
        }
        $tokenRow = fn (ApiTokenRow $row, WorkingState $working): array => $this->row($row, $working);
        $connectionRow = fn (ApiTokenRow $row, WorkingState $working): array => $this->connectionRow($row, $working);

        return $this->render('@Identity/api/index.html.twig', [
            'canIssue' => $this->hasTwoFactor(),
            'connections' => $this->table($slots, ApiTokenLists::CONNECTIONS, self::ROUTE, 'api-connections', $this->rows($myConnections, null), $connectionRow),
            'mine' => $this->table($slots, ApiTokenLists::TOKENS, self::ROUTE, 'api-tokens', $this->rows($mine, null), $tokenRow),
            'others' => null === $others ? null : $this->table($slots, ApiTokenLists::OTHERS_TOKENS, self::ROUTE, 'api-others-tokens', $this->rows($others, $people), $tokenRow),
            'othersConnections' => null === $othersConnections ? null : $this->table($slots, ApiTokenLists::OTHERS_CONNECTIONS, self::ROUTE, 'api-others-connections', $this->rows($othersConnections, $people), $connectionRow),
        ]);
    }

    /**
     * A guest's own connected apps and the address their MCP client connects
     * to; the organisation's people have all of it in the settings.
     */
    #[Route('/connected-apps', name: self::GUEST_ROUTE, methods: ['GET'])]
    #[OpenToGuest]
    public function connectedApps(Request $request): Response
    {
        if ($this->staff->isStaff()) {
            return $this->redirectToRoute(self::ROUTE);
        }
        try {
            $connections = $this->live($this->tokens->myConnections());
        } catch (ApiTokenRefused $refused) {
            throw $this->denied($refused);
        }
        $slots = [ApiTokenLists::CONNECTIONS => ListSlot::first($request->query->all())];
        $built = $this->listTable->built($slots[ApiTokenLists::CONNECTIONS], $this->lists->list(ApiTokenLists::CONNECTIONS));
        if (null !== $built) {
            return $this->redirectToRoute(self::GUEST_ROUTE, $built, Response::HTTP_SEE_OTHER);
        }

        return $this->render('@Identity/api/connected-apps.html.twig', [
            'server' => $this->mcpServer(),
            'connections' => $this->table($slots, ApiTokenLists::CONNECTIONS, self::GUEST_ROUTE, 'connected-apps', array_map(fn (ApiToken $token): ApiTokenRow => $this->tokenRow($token, null), $connections), fn (ApiTokenRow $row, WorkingState $working): array => $this->connectionRow($row, $working, self::GUEST_DISCONNECT_ROUTE)),
        ]);
    }

    #[Route('/connected-apps/{id}/disconnect', name: self::GUEST_DISCONNECT_ROUTE, requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    #[OpenToGuest]
    public function disconnect(string $id, Request $request): RedirectResponse
    {
        $this->checkToken($request);
        try {
            $token = $this->tokens->revoke($id);
        } catch (ApiTokenRefused $refused) {
            throw ApiTokenRefused::UNKNOWN === $refused->reason ? new NotFoundHttpException($refused->getMessage(), $refused) : $this->denied($refused);
        }
        $this->addFlash('success', $this->translator->trans(null === $token->oauthClientId() ? 'api_tokens.flash.revoked' : 'api_tokens.flash.disconnected'));

        return $this->redirectToRoute(self::GUEST_ROUTE);
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
     * the one answer that shows the secret. A token acts as the person, so they
     * confirm it is them first (GitHub asks the same before a personal token).
     */
    #[Route('/settings/api/new', name: self::NEW_ROUTE, methods: ['GET', 'POST'])]
    #[RequiresReauthentication(RequiresReauthentication::API_TOKEN)]
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
            $token = $this->tokens->revoke($id);
        } catch (ApiTokenRefused $refused) {
            throw ApiTokenRefused::UNKNOWN === $refused->reason ? new NotFoundHttpException($refused->getMessage(), $refused) : $this->denied($refused);
        }
        $this->addFlash('success', $this->translator->trans(null === $token->oauthClientId() ? 'api_tokens.flash.revoked' : 'api_tokens.flash.disconnected'));

        return $this->redirectToRoute(self::ROUTE);
    }

    /**
     * @return array<string, string> scope => label
     */
    private function offeredScopes(): array
    {
        return $this->scopeOptions->offered();
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
     * @return list<ApiToken> the live OAuth connections of the other people; for an owner
     *
     * @throws ApiTokenRefused
     */
    private function othersConnections(): array
    {
        $me = $this->actor->userId();

        return array_values(array_filter($this->live($this->tokens->organisationConnections()), static fn (ApiToken $token): bool => $token->userId() !== $me));
    }

    /**
     * A disconnected client is gone: its revoked row is no longer listed.
     *
     * @param list<ApiToken> $connections
     *
     * @return list<ApiToken>
     */
    private function live(array $connections): array
    {
        return array_values(array_filter($connections, static fn (ApiToken $token): bool => !$token->revokedAt() instanceof \DateTimeImmutable));
    }

    /**
     * One table of the page as every list is (docs/design/list-pages.md): its views, the
     * filter sheet, the search, its Settings sheet with columns, and widths a person drags,
     * all of it in its slot of the address so the other tables keep their own.
     *
     * @param array<string, ListSlot>                                   $slots the page's tables by their list
     * @param list<ApiTokenRow>                                         $rows
     * @param \Closure(ApiTokenRow, WorkingState): array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function table(array $slots, string $key, string $route, string $id, array $rows, \Closure $row): array
    {
        $list = $this->lists->list($key);
        $slot = $slots[$key];
        $page = $this->listTable->of($slot, $list, $key, $route, $id, $rows, $row, $this->valueOf(...), static fn (ApiTokenRow $one): string => $one->token->name().' '.($one->person->name ?? ''), $this->translator->trans('api_tokens.search'));

        return $page->with($this->filterSheet->of($list, $page->shown, $page->href, pinned: $page->pinned, slot: $slot));
    }

    /**
     * The value of a field of the lists on a token or a connection, as RowConditions compares it.
     */
    private function valueOf(ApiTokenRow $row, string $field): mixed
    {
        return match ($field) {
            ApiTokenLists::NAME => $row->token->name(),
            ApiTokenLists::PERSON => $row->person->name ?? '',
            ApiTokenLists::STATE => $row->token->state($this->clock->now())->value,
            ApiTokenLists::VERIFIED => null !== $row->host,
            default => null,
        };
    }

    /**
     * @param list<ApiToken>             $tokens
     * @param array<string, Person>|null $people the people by their user, for a table of the other people's
     *
     * @return list<ApiTokenRow>
     */
    private function rows(array $tokens, ?array $people): array
    {
        return array_map(fn (ApiToken $token): ApiTokenRow => $this->tokenRow($token, null === $people ? null : ($people[$token->userId()] ?? null)), $tokens);
    }

    /**
     * A token or a connection with the person it belongs to, and for a connected OAuth client
     * the host that vouches for its name.
     */
    private function tokenRow(ApiToken $token, ?Person $person): ApiTokenRow
    {
        $clientId = $token->oauthClientId();
        $client = null === $clientId ? null : $this->oauth->client($clientId);

        return new ApiTokenRow($token, $person, $client instanceof OAuthClient ? $client->verifiedHost() : null);
    }

    /**
     * One connected OAuth client: its name, who vouches for the name and what it may do.
     *
     * @return array<string, mixed>
     */
    private function connectionRow(ApiTokenRow $row, WorkingState $working, string $disconnectRoute = self::REVOKE_ROUTE): array
    {
        $token = $row->token;

        return [
            'id' => $token->id(),
            'label' => $token->name(),
            'secondary' => $row->host ?? $this->translator->trans('api_tokens.connection.unverified'),
            'cells' => $working->display->cells($row),
            'actions' => [
                ['label' => $this->translator->trans('api_tokens.action.disconnect'), 'href' => $this->generateUrl($disconnectRoute, ['id' => $token->id()]), 'method' => 'POST', 'confirm' => [
                    'title' => $this->translator->trans('api_tokens.confirm.disconnect.title', ['name' => $token->name()]),
                    'text' => $this->translator->trans('api_tokens.confirm.disconnect.text'),
                    'accept' => $this->translator->trans('api_tokens.action.disconnect'),
                    'tone' => 'danger',
                ]],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(ApiTokenRow $row, WorkingState $working): array
    {
        $token = $row->token;

        return [
            'id' => $token->id(),
            'label' => $token->name(),
            'secondary' => '…'.$token->lastFour(),
            'cells' => $working->display->cells($row),
            'actions' => ApiTokenState::Active === $token->state($this->clock->now()) ? [
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
