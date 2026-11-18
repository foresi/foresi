<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Infrastructure\OAuth;

use Foresi\Identity\Application\OAuth\OAuthClients;
use Foresi\Identity\Application\OAuth\OAuthRefused;
use Foresi\Identity\Domain\OAuth\OAuthClient;
use Foresi\Shared\Infrastructure\Security\PublicRoute;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Dynamic client registration (RFC 7591), for MCP clients that do not publish
 * a client ID metadata document (ADR 0036). Only public clients; a few
 * registrations per address and hour. Registering grants nothing: a person
 * still has to approve the client on the consent screen.
 */
final readonly class OAuthRegisterController
{
    public const string ROUTE = 'oauth_register';

    public function __construct(
        private OAuthClients $clients,
        #[Autowire(service: 'limiter.oauth_register')]
        private RateLimiterFactoryInterface $limiter,
    ) {
    }

    #[Route('/oauth/register', name: self::ROUTE, defaults: [PublicRoute::DEFAULT => true], methods: ['POST', 'OPTIONS'], stateless: true)]
    public function __invoke(Request $request): Response
    {
        if ($request->isMethod('OPTIONS')) {
            return OAuthEndpoints::preflight();
        }
        if (!$this->limiter->create((string) $request->getClientIp())->consume()->isAccepted()) {
            return OAuthEndpoints::error('slow_down', 'Too many registrations from this address; try again later.', Response::HTTP_TOO_MANY_REQUESTS);
        }
        try {
            $metadata = json_decode($request->getContent(), true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $metadata = null;
        }
        if (!\is_array($metadata) || array_is_list($metadata)) {
            return OAuthEndpoints::error(OAuthRefused::INVALID_CLIENT_METADATA, 'Send the client metadata as a JSON object.');
        }
        try {
            $client = $this->clients->register($metadata);
        } catch (OAuthRefused $refused) {
            return OAuthEndpoints::error($refused->error, $refused->description);
        }

        return OAuthEndpoints::json($this->describe($client), Response::HTTP_CREATED);
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(OAuthClient $client): array
    {
        return [
            'client_id' => $client->clientId(),
            'client_id_issued_at' => $client->createdAt()->getTimestamp(),
            'client_name' => $client->name(),
            'redirect_uris' => $client->redirectUris(),
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ];
    }
}
