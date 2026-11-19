<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Infrastructure\Api;

use Foresi\Party\Application\Query\ContactDetails;
use Foresi\Party\Application\Query\ContactList;
use Foresi\Party\Application\View\ContactLists;
use Foresi\Shared\Application\Api\ScopeGuard;
use Foresi\Shared\Application\View\AppliedView;
use Foresi\Shared\Application\View\SavedViews;
use Foresi\Shared\Domain\View\SavedView;
use Foresi\Shared\Infrastructure\Api\Cursor;
use Foresi\Shared\Infrastructure\View\Api\ViewJson;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The address book of the organisation, read by a personal API token with the
 * contacts:read scope. The query services decide who may read; what they refuse is
 * answered by PartyErrors.
 */
#[AsController]
final readonly class ContactApiController
{
    public const string READ_SCOPE = 'contacts:read';
    public const string LIST = 'api_v1_contacts';
    public const string SHOW = 'api_v1_contact';

    public function __construct(private ScopeGuard $scope, private ContactList $list, private ContactDetails $details, private SavedViews $views, private ViewJson $json, private ContactLists $lists)
    {
    }

    #[Route('/api/v1/contacts', name: self::LIST, methods: ['GET'])]
    #[OA\Tag(name: 'Contacts')]
    #[OA\Response(response: 200, description: 'The contacts, newest first, the cursor of the next slice and the saved view applied.', content: new OA\JsonContent(ref: '#/components/schemas/ContactPage'))]
    #[OA\Response(response: 400, description: 'A parameter cannot be used, such as a filter with an unknown field; parameter names its path, such as filter.conditions[0].op.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the contacts:read scope, or views:read for a view.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 404, description: 'No saved view with this id the token\'s person sees.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 409, description: 'The saved view filters on what its list no longer has.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 429, description: 'The token made too many requests.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function list(#[MapQueryString(validationFailedStatusCode: 400)] ContactListQuery $query): JsonResponse
    {
        $this->scope->require(self::READ_SCOPE);
        $applied = AppliedView::of($this->views, $this->scope, $this->lists->list(), $query->view, $query->filter);
        $slice = $this->list->slice($query->filter($applied->filter), Cursor::decode($query->cursor), $query->limit);

        return new JsonResponse([
            'data' => array_map(ContactJson::row(...), $slice->rows),
            'next_cursor' => Cursor::next($slice->hasMore ? $slice->lastId() : null),
            'applied_view' => $applied->view instanceof SavedView ? $this->json->view($applied->view) : null,
        ]);
    }

    #[Route('/api/v1/contacts/{contactId}', name: self::SHOW, methods: ['GET'])]
    #[OA\Tag(name: 'Contacts')]
    #[OA\Response(response: 200, description: 'The contact with its addresses, e-mails, bank accounts and contact persons.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ContactDetail')], type: 'object'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the contacts:read scope.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 404, description: 'The organisation has no such contact.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 429, description: 'The token made too many requests.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function show(string $contactId): JsonResponse
    {
        $this->scope->require(self::READ_SCOPE);

        return new JsonResponse(['data' => ContactJson::detail($this->details->of($contactId))]);
    }
}
