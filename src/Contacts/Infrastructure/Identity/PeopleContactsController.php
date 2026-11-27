<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Contacts\Infrastructure\Identity;

use Foresi\Identity\Application\OwnerCheck;
use Foresi\Party\Application\PartyQuery;
use Foresi\Party\Application\PartyRow;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The contact picker of the people screens: the owner finds the contact a person
 * represents, of any kind, in the address book.
 */
final class PeopleContactsController extends AbstractController
{
    public const string ROUTE = 'app_people_contacts';
    private const int MAX_QUERY = 100;

    public function __construct(
        private readonly PartyQuery $parties,
        private readonly OwnerCheck $owner,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/settings/people/contacts', name: self::ROUTE, methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        if (!$this->owner->isOwner()) {
            throw new AccessDeniedHttpException('Only the owner manages the people of the organisation.');
        }
        $page = $this->parties->search(mb_substr($request->query->getString('q'), 0, self::MAX_QUERY), null, false, 'name', 1);

        return $this->json(['results' => array_map(fn (PartyRow $row): array => [
            'id' => $row->id,
            'label' => $row->displayName,
            'hint' => $row->email ?? $row->registrationNumber ?? $this->translator->trans('party.kind.'.$row->kind->value),
        ], $page->rows)]);
    }
}
