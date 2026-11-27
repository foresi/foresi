<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Integration\Contacts;

use Foresi\Identity\Contract\StaffCheck;
use Foresi\Party\Application\PartyQuery;
use Foresi\Party\Application\PartyRow;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The contacts the client field of a project offers, and the client conditions of the
 * lists pick from: any contact of the address book, for the staff.
 */
final class ProjectClientsController extends AbstractController
{
    public const string ROUTE = 'app_project_clients';
    private const int MAX_QUERY = 100;

    public function __construct(private readonly PartyQuery $parties, private readonly StaffCheck $staff, private readonly TranslatorInterface $translator)
    {
    }

    #[Route('/projects/clients', name: self::ROUTE, methods: ['GET'])]
    public function clients(Request $request): JsonResponse
    {
        if (!$this->staff->isStaff()) {
            throw new AccessDeniedHttpException('Only the staff work on the projects of the organisation.');
        }
        $page = $this->parties->search(mb_substr($request->query->getString('q'), 0, self::MAX_QUERY), null, false, 'name', 1);

        return $this->json(['results' => array_map(fn (PartyRow $row): array => [
            'id' => $row->id,
            'label' => $row->displayName,
            'hint' => $row->email ?? $row->registrationNumber ?? $this->translator->trans('party.kind.'.$row->kind->value),
        ], $page->rows)]);
    }
}
