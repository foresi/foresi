<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Infrastructure\OAuth;

use Foresi\Identity\Application\OAuth\OAuthRefused;
use Foresi\Identity\Application\OAuth\OAuthTokens;
use Foresi\Shared\Infrastructure\Security\PublicRoute;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The token endpoint (RFC 6749, 3.2; ADR 0036): form-encoded, public clients
 * only, so the client is named by client_id and proves itself with PKCE. The
 * answer holds secrets and is never cached.
 */
final readonly class OAuthTokenController
{
    public const string ROUTE = 'oauth_token';

    public function __construct(
        private OAuthTokens $tokens,
        private OAuthEndpoints $endpoints,
    ) {
    }

    #[Route('/oauth/token', name: self::ROUTE, defaults: [PublicRoute::DEFAULT => true], methods: ['POST', 'OPTIONS'], stateless: true)]
    public function __invoke(Request $request): Response
    {
        if ($request->isMethod('OPTIONS')) {
            return OAuthEndpoints::preflight();
        }
        if ('form' !== $request->getContentTypeFormat()) {
            return OAuthEndpoints::error(OAuthRefused::INVALID_REQUEST, 'Send the parameters form-encoded (application/x-www-form-urlencoded).');
        }
        try {
            $issued = $this->tokens->grant($request->request->all(), $this->endpoints->resource());
        } catch (OAuthRefused $refused) {
            return OAuthEndpoints::error($refused->error, $refused->description, OAuthRefused::INVALID_CLIENT === $refused->error ? Response::HTTP_UNAUTHORIZED : Response::HTTP_BAD_REQUEST);
        }
        $response = OAuthEndpoints::json($issued->toArray());
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
