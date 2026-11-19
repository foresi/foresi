<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Infrastructure\OAuth;

use Foresi\Identity\Application\ApiTokenRefused;
use Foresi\Identity\Application\OAuth\AuthorizationRequest;
use Foresi\Identity\Application\OAuth\OAuthAuthorization;
use Foresi\Identity\Application\OAuth\OAuthRefused;
use Foresi\Identity\Application\OpenToGuest;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Identity\Domain\OAuth\RedirectUri;
use Foresi\Identity\Infrastructure\Controller\ApiScopeOptions;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The authorization endpoint with its consent screen (ADR 0036). It sits
 * behind the ordinary sign-in, two-factor step included; the firewall brings
 * the person back here after it. The screen names the client, where it sends
 * the person back and what it may do; read access the client asked for stays
 * ticked, everything else can be changed. Errors about the client or its
 * redirect URI are shown here and never redirected to. A guest reaches it too
 * and is offered only the scopes a guest may hold (ADR 0041).
 */
#[OpenToGuest]
final class OAuthAuthorizeController extends AbstractController
{
    public const string ROUTE = 'oauth_authorize';
    public const string TOKEN = 'oauth_consent';

    public function __construct(
        private readonly OAuthAuthorization $authorization,
        private readonly OAuthEndpoints $endpoints,
        private readonly ApiScopeOptions $scopes,
    ) {
    }

    #[Route('/oauth/authorize', name: self::ROUTE, methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $parameters = $request->isMethod('POST') ? $request->request->all() : $request->query->all();
        $issuer = $this->endpoints->issuer($request);
        try {
            $authorization = $this->authorization->check($parameters, $this->endpoints->resource());
        } catch (OAuthRefused $refused) {
            if (null !== $refused->redirectUri) {
                return $this->leave(OAuthAuthorization::errorRedirect($refused->redirectUri, $refused, $issuer));
            }

            return $this->render('@Identity/oauth/error.html.twig', ['description' => $refused->description], new Response(status: Response::HTTP_BAD_REQUEST));
        }

        try {
            $this->authorization->connectingPerson();
            $canConnect = true;
        } catch (ApiTokenRefused $refused) {
            if (ApiTokenRefused::TWO_FACTOR_REQUIRED !== $refused->reason) {
                throw new AccessDeniedHttpException($refused->getMessage(), $refused);
            }
            $canConnect = false;
        }

        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid(self::TOKEN, $request->request->getString('_token'))) {
                throw new AccessDeniedHttpException('Invalid CSRF token.');
            }
            if ('allow' !== $request->request->getString('decision')) {
                return $this->leave($this->authorization->deny($authorization, $issuer));
            }
            $granted = array_values(array_intersect(array_keys($this->offered()), array_filter($request->request->all('scopes'), \is_string(...))));
            try {
                return $this->leave($this->authorization->approve($authorization, array_map(ApiScope::from(...), $granted), $issuer));
            } catch (ApiTokenRefused $refused) {
                throw new AccessDeniedHttpException($refused->getMessage(), $refused);
            } catch (OAuthRefused $refused) {
                $error = $refused->description;
            }
        }

        return $this->render('@Identity/oauth/consent.html.twig', [
            'client' => $authorization->client,
            'verifiedHost' => $authorization->client->verifiedHost(),
            'redirectHost' => RedirectUri::displayHost($authorization->redirectUri),
            'parameters' => $authorization->parameters(),
            'scopes' => $this->scopeRows($authorization),
            'canConnect' => $canConnect,
            'error' => $error,
        ], new Response(status: null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /**
     * @return list<array{value: string, label: string, checked: bool, required: bool}>
     */
    private function scopeRows(AuthorizationRequest $authorization): array
    {
        $offered = $this->offered();
        if ($this->authorization->connectsAsGuest()) {
            // A guest's rows are what the asked scopes come to for a guest: "write" ticks writing tasks and reading projects.
            $asked = $this->granted($authorization->scopes, array_keys($offered));
            $required = $this->granted($authorization->requiredScopes(), array_keys($offered));
        } else {
            $asked = array_map(static fn (ApiScope $scope): string => $scope->value, $authorization->scopes);
            $required = array_map(static fn (ApiScope $scope): string => $scope->value, $authorization->requiredScopes());
        }
        $rows = [];
        foreach ($offered as $value => $label) {
            $rows[] = ['value' => $value, 'label' => $label, 'checked' => \in_array($value, $asked, true), 'required' => \in_array($value, $required, true)];
        }

        return $rows;
    }

    /**
     * @param list<ApiScope> $scopes
     * @param list<string>   $values
     *
     * @return list<string> the values the scopes grant
     */
    private function granted(array $scopes, array $values): array
    {
        return array_values(array_filter($values, static fn (string $value): bool => array_any($scopes, static fn (ApiScope $scope): bool => $scope->grants(ApiScope::from($value)))));
    }

    /**
     * @return array<string, string> scope => label, for a guest only those a guest may hold
     */
    private function offered(): array
    {
        return $this->authorization->connectsAsGuest() ? $this->scopes->offeredToGuests() : $this->scopes->offered();
    }

    /**
     * Back to the client, which may be another site or a native app: never through Turbo.
     */
    private function leave(string $url): RedirectResponse
    {
        $response = new RedirectResponse($url);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
