<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\ExchangeRate\Infrastructure\Api;

use Foresi\ExchangeRate\Application\RateSourceReads;
use Foresi\Shared\Application\Api\ScopeGuard;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The source of the firm's exchange rates, read by a personal API token with
 * the exchange_rates:read scope, by the owners and the members alike. What
 * the use case refuses is answered by the shared refusal contract (ADR 0032).
 */
#[AsController]
final readonly class ExchangeRateApiController
{
    public const string READ_SCOPE = 'exchange_rates:read';
    public const string SOURCE = 'api_v1_exchange_rate_source';

    public function __construct(private ScopeGuard $scope, private RateSourceReads $reads)
    {
    }

    #[Route('/api/v1/exchange-rate/source', name: self::SOURCE, methods: ['GET'])]
    #[OA\Tag(name: 'Exchange rates')]
    #[OA\Response(response: 200, description: 'The source the firm converts foreign currencies at, whether a person chose it, the default of its country, and the sources it may choose.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ExchangeRateSource')], type: 'object'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the exchange_rates:read scope, or belongs to a guest.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function source(): JsonResponse
    {
        $this->scope->require(self::READ_SCOPE);

        return new JsonResponse(['data' => ExchangeRateJson::source($this->reads->current())]);
    }
}
