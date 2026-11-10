<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Infrastructure\Api;

use App\Party\Application\Query\ContactDetails;
use App\Party\Application\Query\ContactList;
use App\Shared\Application\Api\ScopeGuard;
use App\Shared\Infrastructure\Api\Cursor;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The address book of the organisation, read by a personal API token with the
 * read scope. The query services decide who may read; what they refuse is
 * answered by PartyErrors.
 */
#[AsController]
final readonly class ContactApiController
{
    public const string LIST = 'api_v1_contacts';
    public const string SHOW = 'api_v1_contact';

    public function __construct(private ScopeGuard $scope, private ContactList $list, private ContactDetails $details)
    {
    }

    #[Route('/api/v1/contacts', name: self::LIST, methods: ['GET'])]
    #[OA\Tag(name: 'Contacts')]
    #[OA\Response(response: 200, description: 'The contacts, newest first, and the cursor of the next slice.', content: new OA\JsonContent(ref: '#/components/schemas/ContactPage'))]
    #[OA\Response(response: 400, description: 'A parameter cannot be used.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the read scope.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 429, description: 'The token made too many requests.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function list(#[MapQueryString(validationFailedStatusCode: 400)] ContactListQuery $query): JsonResponse
    {
        $this->scope->require(ScopeGuard::READ);
        $slice = $this->list->slice($query->filter(), Cursor::decode($query->cursor), $query->limit);

        return new JsonResponse(['data' => array_map(ContactJson::row(...), $slice->rows), 'next_cursor' => Cursor::next($slice->hasMore ? $slice->lastId() : null)]);
    }

    #[Route('/api/v1/contacts/{id}', name: self::SHOW, methods: ['GET'])]
    #[OA\Tag(name: 'Contacts')]
    #[OA\Response(response: 200, description: 'The contact with its addresses, e-mails, bank accounts and contact persons.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ContactDetail')], type: 'object'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the read scope.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 404, description: 'The organisation has no such contact.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 429, description: 'The token made too many requests.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function show(string $id): JsonResponse
    {
        $this->scope->require(ScopeGuard::READ);

        return new JsonResponse(['data' => ContactJson::detail($this->details->of($id))]);
    }
}
