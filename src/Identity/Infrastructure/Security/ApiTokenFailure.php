<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Infrastructure\Security;

use Foresi\Identity\Infrastructure\OAuth\OAuthEndpoints;
use Foresi\Shared\Infrastructure\Api\ProblemResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * The 401 problem documents of the api and mcp firewalls (RFC 6750): no
 * bearer token at all, or one that signs nobody in. On /mcp the challenge
 * names the protected resource metadata, where an MCP client finds how to
 * sign in with OAuth (RFC 9728, ADR 0036).
 */
final readonly class ApiTokenFailure implements AuthenticationFailureHandlerInterface, AuthenticationEntryPointInterface
{
    public const string MISSING = 'missing_token';

    public function start(Request $request, ?AuthenticationException $authException = null): JsonResponse
    {
        return ProblemResponse::create(Response::HTTP_UNAUTHORIZED, self::MISSING, 'Send a personal API token as a bearer token.', ['WWW-Authenticate' => $this->challenge($request, null)]);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): JsonResponse
    {
        $problem = $exception instanceof ApiTokenRejected ? $exception->problem() : ApiTokenRejected::INVALID;
        $detail = match ($problem) {
            ApiTokenRejected::EXPIRED => 'The API token has expired.',
            ApiTokenRejected::REVOKED => 'The API token has been revoked.',
            default => 'The API token is not valid.',
        };

        return ProblemResponse::create(Response::HTTP_UNAUTHORIZED, $problem, $detail, ['WWW-Authenticate' => $this->challenge($request, 'invalid_token')]);
    }

    private function challenge(Request $request, ?string $error): string
    {
        $parameters = null === $error ? [] : ['error="'.$error.'"'];
        if (1 === preg_match('#^/mcp(/|$)#', $request->getPathInfo())) {
            $parameters[] = 'resource_metadata="'.OAuthEndpoints::resourceMetadataUrl($request).'"';
        }

        return 'Bearer'.([] === $parameters ? '' : ' '.implode(', ', $parameters));
    }
}
