<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Infrastructure\Api;

use Foresi\Party\Application\NoFirmYet;
use Foresi\Party\Application\PartyNotAllowed;
use Foresi\Party\Application\Query\FirmDetails;
use Foresi\Shared\Application\Api\ScopeGuard;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The one firm of the instance (ADR 0039), read by a personal API token with the
 * firm:read scope.
 */
#[AsController]
final readonly class FirmApiController
{
    public const string SHOW = 'api_v1_firm';
    /** The scope of the firm, which Party declares. */
    public const string READ_SCOPE = 'firm:read';

    public function __construct(private ScopeGuard $scope, private FirmDetails $firm)
    {
    }

    #[Route('/api/v1/firm', name: self::SHOW, methods: ['GET'])]
    #[OA\Tag(name: 'Contacts')]
    #[OA\Response(response: 200, description: 'The firm of the instance: the company that issues its documents and receives its payments.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Firm')], type: 'object'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the firm:read scope, or its person is a guest.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 404, description: 'The instance has no firm yet: its first-run setup has not happened.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 429, description: 'The token made too many requests.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function show(): JsonResponse
    {
        $this->scope->require(self::READ_SCOPE);
        try {
            $firm = $this->firm->get();
        } catch (PartyNotAllowed $refused) {
            throw new AccessDeniedHttpException('Only the owners and members of the organisation read its firm.', $refused);
        } catch (NoFirmYet $none) {
            throw new NotFoundHttpException('The instance has no firm yet.', $none);
        }

        return new JsonResponse(['data' => FirmJson::detail($firm, $this->firm->vatStatuses())]);
    }
}
