<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Infrastructure\OAuth;

use Foresi\Identity\Domain\ApiScope;
use Foresi\Shared\Application\Api\ApiResources;
use Foresi\Shared\Infrastructure\Security\PublicRoute;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The two metadata documents an MCP client reads before it signs in (ADR
 * 0036): that of the MCP server as a protected resource (RFC 9728) and that of
 * the authorization server (RFC 8414). Both are public and hold no data.
 */
final readonly class OAuthMetadataController
{
    public function __construct(
        private OAuthEndpoints $endpoints,
        private UrlGeneratorInterface $urls,
        private ApiResources $resources,
    ) {
    }

    #[Route(OAuthEndpoints::RESOURCE_METADATA_PATH.'/mcp', name: 'oauth_resource_metadata', defaults: [PublicRoute::DEFAULT => true], methods: ['GET', 'OPTIONS'], stateless: true)]
    #[Route(OAuthEndpoints::RESOURCE_METADATA_PATH, name: 'oauth_resource_metadata_root', defaults: [PublicRoute::DEFAULT => true], methods: ['GET', 'OPTIONS'], stateless: true)]
    public function resource(Request $request): Response
    {
        if ($request->isMethod('OPTIONS')) {
            return OAuthEndpoints::preflight();
        }

        return OAuthEndpoints::json([
            'resource' => $this->endpoints->resource(),
            'authorization_servers' => [$this->endpoints->issuer($request)],
            'scopes_supported' => $this->scopes(),
            'bearer_methods_supported' => ['header'],
            'resource_name' => 'Foresi',
        ]);
    }

    #[Route('/.well-known/oauth-authorization-server', name: 'oauth_server_metadata', defaults: [PublicRoute::DEFAULT => true], methods: ['GET', 'OPTIONS'], stateless: true)]
    public function server(Request $request): Response
    {
        if ($request->isMethod('OPTIONS')) {
            return OAuthEndpoints::preflight();
        }
        $url = fn (string $route): string => $this->urls->generate($route, [], UrlGeneratorInterface::ABSOLUTE_URL);

        return OAuthEndpoints::json([
            'issuer' => $this->endpoints->issuer($request),
            'authorization_endpoint' => $url(OAuthAuthorizeController::ROUTE),
            'token_endpoint' => $url(OAuthTokenController::ROUTE),
            'registration_endpoint' => $url(OAuthRegisterController::ROUTE),
            'scopes_supported' => $this->scopes(),
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'code_challenge_methods_supported' => ['S256'],
            'client_id_metadata_document_supported' => true,
            'authorization_response_iss_parameter_supported' => true,
        ]);
    }

    /**
     * @return list<string>
     */
    private function scopes(): array
    {
        $scopes = [ApiScope::READ, ApiScope::WRITE];
        foreach ($this->resources->declared() as $resource) {
            $scopes[] = ApiScope::of($resource, false)->value;
            $scopes[] = ApiScope::of($resource, true)->value;
        }

        return $scopes;
    }
}
