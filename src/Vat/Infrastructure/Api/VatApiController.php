<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Infrastructure\Api;

use Foresi\Shared\Application\Api\ScopeGuard;
use Foresi\Vat\Application\VatPeriods;
use Foresi\Vat\Application\VatSettingsReads;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The VAT periods and the payment details read by a personal API token with
 * the vat:read scope, by the owners and the members alike. What the use
 * cases refuse is answered by the shared refusal contract (ADR 0032).
 */
#[AsController]
final readonly class VatApiController
{
    public const string READ_SCOPE = 'vat:read';
    public const string PERIODS = 'api_v1_vat_periods';
    public const string SETTINGS = 'api_v1_vat_settings';

    public function __construct(private ScopeGuard $scope, private VatPeriods $periods, private VatSettingsReads $settings)
    {
    }

    #[Route('/api/v1/vat/periods', name: self::PERIODS, methods: ['GET'])]
    #[OA\Tag(name: 'VAT')]
    #[OA\Parameter(name: 'from', description: 'The first month, such as "2026-11", or a quarter, such as "2026-Q4"; the first period when left out.', in: 'query', schema: new OA\Schema(type: 'string', example: '2026-11'))]
    #[OA\Parameter(name: 'to', description: 'The last month or quarter; 12 months after this one when left out, at most 24.', in: 'query', schema: new OA\Schema(type: 'string', example: '2027-12'))]
    #[OA\Response(response: 200, description: 'The VAT periods of the firm from the day it became a payer, each with its due date, always an estimate; or none with the reason why.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/VatPeriods')], type: 'object'))]
    #[OA\Response(response: 400, description: 'A month cannot be used (invalid_parameter: from_invalid, to_invalid, range_invalid, or range_too_long beyond 24 months).', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the vat:read scope.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 404, description: 'The instance has no firm yet.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function periods(Request $request): JsonResponse
    {
        $this->scope->require(self::READ_SCOPE);

        return new JsonResponse(['data' => VatJson::periods($this->periods->list($this->optional($request, 'from'), $this->optional($request, 'to')))]);
    }

    #[Route('/api/v1/vat/settings', name: self::SETTINGS, methods: ['GET'])]
    #[OA\Tag(name: 'VAT')]
    #[OA\Response(response: 200, description: 'Where the firm pays its VAT, null until it is set, and the country\'s suggestion.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/VatSettings')], type: 'object'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the vat:read scope.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 404, description: 'The instance has no firm yet.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function settings(): JsonResponse
    {
        $this->scope->require(self::READ_SCOPE);

        return new JsonResponse(['data' => VatJson::settings($this->settings->current())]);
    }

    private function optional(Request $request, string $name): ?string
    {
        $value = trim($request->query->getString($name));

        return '' === $value ? null : $value;
    }
}
