<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Infrastructure\Api;

use Foresi\Shared\Application\Api\ScopeGuard;
use Foresi\TaxesAndContributions\Application\ProfileReads;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The owner profile read by a personal API token with the taxes:read scope,
 * and only by the owner (B7): a member with the scope is refused with
 * owner_only. What the use cases refuse is answered by the shared refusal
 * contract (ADR 0032).
 */
#[AsController]
final readonly class TaxesApiController
{
    public const string READ_SCOPE = 'taxes:read';
    public const string PROFILE = 'api_v1_taxes_profile';

    public function __construct(private ScopeGuard $scope, private ProfileReads $reads)
    {
    }

    #[Route('/api/v1/taxes-and-contributions/profile', name: self::PROFILE, methods: ['GET'])]
    #[OA\Tag(name: 'Taxes')]
    #[OA\Response(response: 200, description: 'The owner profile: the dated statuses, the inputs of each year and the authority accounts.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TaxProfile')], type: 'object'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the taxes:read scope, or its person is not the owner (owner_only).', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function profile(): JsonResponse
    {
        $this->scope->require(self::READ_SCOPE);

        return new JsonResponse(['data' => TaxesJson::profile($this->reads->profile())]);
    }
}
