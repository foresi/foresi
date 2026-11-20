<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Infrastructure\Api;

use Foresi\Shared\Application\Api\ScopeGuard;
use Foresi\TaxesAndContributions\Application\Amounts;
use Foresi\TaxesAndContributions\Application\ProfileReads;
use Foresi\TaxesAndContributions\Application\TaxesRefused;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The owner profile and the actual amounts read by a personal API token with the taxes:read scope,
 * and only by the owner (B7): a member with the scope is refused with
 * owner_only. What the use cases refuse is answered by the shared refusal
 * contract (ADR 0032).
 */
#[AsController]
final readonly class TaxesApiController
{
    public const string READ_SCOPE = 'taxes:read';
    public const string PROFILE = 'api_v1_taxes_profile';
    public const string AMOUNTS = 'api_v1_taxes_amounts';

    public function __construct(private ScopeGuard $scope, private ProfileReads $reads, private Amounts $amounts)
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

    #[Route('/api/v1/taxes-and-contributions/amounts', name: self::AMOUNTS, methods: ['GET'])]
    #[OA\Tag(name: 'Taxes')]
    #[OA\Parameter(name: 'year', description: 'The year, this one when left out; this year runs to today.', in: 'query', schema: new OA\Schema(type: 'integer', example: 2026))]
    #[OA\Response(response: 200, description: 'The actual income and costs paid in the year to date, in CZK: by month, kind and source, with net and VAT, and what the records count.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TaxAmounts')], type: 'object'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the taxes:read scope, or its person is not the owner (owner_only).', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 400, description: 'The year is not one from 2000 to this year (invalid_parameter, reason invalid_year).', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function amounts(Request $request): JsonResponse
    {
        $this->scope->require(self::READ_SCOPE);
        $year = trim($request->query->getString('year'));
        if ('' !== $year && 1 !== preg_match('/^[0-9]{4}\z/', $year)) {
            throw TaxesRefused::invalid('invalid_year', 'year', 'The year is a number such as 2026.');
        }

        return new JsonResponse(['data' => TaxesJson::amounts('' === $year ? $this->amounts->thisYear() : $this->amounts->year((int) $year))]);
    }
}
