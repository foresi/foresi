<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Field\Api;

use Foresi\Shared\Application\Api\ScopeGuard;
use Foresi\Shared\Infrastructure\Api\InvalidParameter;
use Foresi\Shared\Infrastructure\Field\FieldList;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The custom fields the modules add to the records (ADR 0045), read only: what
 * the custom_fields of a contact, a deal, a task, a project or a product hold.
 */
#[AsController]
final readonly class FieldApiController
{
    public const string FIELDS = 'api_v1_fields';

    public function __construct(private ScopeGuard $scope, private FieldList $fields)
    {
    }

    #[Route('/api/v1/fields', name: self::FIELDS, methods: ['GET'])]
    #[OA\Tag(name: 'Custom fields')]
    #[OA\Parameter(name: 'record', description: 'Only the fields of one record kind; it needs the read scope of that record, such as contacts:read.', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['contact', 'deal', 'task', 'project', 'product']))]
    #[OA\Response(response: 200, description: 'The custom fields open to the API that the token\'s person sees, of every record kind the token may read, by record kind and in the order the modules declare them.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/FieldDefinition'))], type: 'object'))]
    #[OA\Response(response: 400, description: 'A record kind that takes no custom fields.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the read scope of the record kind.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 429, description: 'The token made too many requests.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function list(Request $request): JsonResponse
    {
        $this->scope->require(ScopeGuard::ANY);
        $record = $request->query->has('record') ? trim($request->query->getString('record')) : null;
        try {
            return new JsonResponse(['data' => $this->fields->of($record)]);
        } catch (\InvalidArgumentException $invalid) {
            throw new InvalidParameter('record', $invalid->getMessage(), $invalid);
        }
    }
}
