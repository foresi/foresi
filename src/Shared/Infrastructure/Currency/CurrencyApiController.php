<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Currency;

use Foresi\Shared\Application\Api\ScopeGuard;
use Foresi\Shared\Infrastructure\Api\InvalidParameter;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The ISO 4217 currencies an amount may be in (ADR 0049), with the minor units
 * that amount_minor counts in. Any token reads it: it is the same for everyone.
 */
#[AsController]
final readonly class CurrencyApiController
{
    public const string LIST = 'api_v1_currencies';

    public function __construct(private ScopeGuard $scope)
    {
    }

    #[Route('/api/v1/currencies', name: self::LIST, methods: ['GET'])]
    #[OA\Tag(name: 'Currencies')]
    #[OA\Parameter(name: 'historic', description: 'true to list the withdrawn codes too, such as HRK, which older documents may be in.', in: 'query', required: false, schema: new OA\Schema(type: 'boolean', default: false))]
    #[OA\Response(response: 200, description: 'The currencies, ordered by code: code, English ISO name, minor units (the decimals of the currency), numeric code and whether ISO 4217 lists it as withdrawn.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [
        new OA\Property(property: 'code', type: 'string', example: 'BHD'),
        new OA\Property(property: 'name', type: 'string', example: 'Bahraini Dinar'),
        new OA\Property(property: 'minor_units', type: 'integer', example: 3),
        new OA\Property(property: 'numeric_code', type: 'integer', example: 48, nullable: true),
        new OA\Property(property: 'historic', type: 'boolean', example: false),
    ], type: 'object'))], type: 'object'))]
    #[OA\Response(response: 400, description: 'historic is not true or false.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 429, description: 'The token made too many requests.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function list(Request $request): JsonResponse
    {
        $this->scope->require(ScopeGuard::ANY);
        $historic = $request->query->has('historic') ? $request->query->getString('historic') : 'false';
        if (!\in_array($historic, ['true', 'false'], true)) {
            throw new InvalidParameter('historic', 'must be true or false.');
        }

        return new JsonResponse(['data' => CurrencyList::rows('true' === $historic)]);
    }
}
