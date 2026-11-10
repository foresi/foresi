<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Api;

use App\Identity\Application\Query\CurrentApiIdentity;
use App\Identity\Domain\ApiScope;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Who a token acts as: the person, the organisation and the token itself, for
 * a client to check its set-up.
 */
#[AsController]
final readonly class MeController
{
    public const string ROUTE = 'api_v1_me';

    public function __construct(private CurrentApiIdentity $identity)
    {
    }

    #[Route('/api/v1/me', name: self::ROUTE, methods: ['GET'])]
    #[OA\Tag(name: 'Identity')]
    #[OA\Response(response: 200, description: 'The person, the organisation and the token.', content: new OA\JsonContent(ref: '#/components/schemas/Me'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the read scope.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function __invoke(): JsonResponse
    {
        $identity = $this->identity->get();

        return new JsonResponse([
            'user' => ['id' => $identity->userId, 'email' => $identity->email],
            'tenant' => ['id' => $identity->tenantId->toString(), 'name' => $identity->tenantName],
            'token' => [
                'id' => $identity->token->tokenId,
                'name' => $identity->token->tokenName,
                'scopes' => array_map(static fn (ApiScope $scope): string => $scope->value, $identity->token->scopes),
                'expires_at' => $identity->token->expiresAt->format(\DATE_ATOM),
            ],
        ]);
    }
}
