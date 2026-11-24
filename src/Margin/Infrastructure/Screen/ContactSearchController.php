<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Infrastructure\Screen;

use Foresi\Margin\Application\MarginAccess;
use Foresi\Margin\Domain\MarginRefused;
use Foresi\Party\Application\PartyQuery;
use Foresi\Party\Application\PartyRow;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The contacts for the combobox of the Margin dialogs (a client of a share, the supplier of a planned item), found by
 * name, registration number, VAT ID or e-mail as the address book finds them, archived ones left out. Staff only.
 */
final class ContactSearchController extends AbstractController
{
    public const string ROUTE = 'app_margin_contacts';

    private const int SEARCH_LIMIT = 10;
    private const int MIN_SEARCH = 3;

    public function __construct(private readonly MarginAccess $access, private readonly PartyQuery $parties)
    {
    }

    #[Route('/margin/contacts', name: self::ROUTE, methods: ['GET'])]
    public function contacts(Request $request): JsonResponse
    {
        try {
            $this->access->denyUnlessStaff();
        } catch (MarginRefused $refused) {
            throw new AccessDeniedHttpException($refused->getMessage(), $refused);
        }
        $text = trim(mb_substr($request->query->getString('q'), 0, 100));
        $rows = mb_strlen($text) < self::MIN_SEARCH && !ctype_digit($text) ? [] : \array_slice($this->parties->search($text, null, false, 'name', 1)->rows, 0, self::SEARCH_LIMIT);

        return $this->json(['results' => array_map(static fn (PartyRow $row): array => [
            'id' => $row->id,
            'label' => $row->displayName,
            'hint' => $row->registrationNumber ?? $row->vatId ?? '',
        ], $rows)]);
    }
}
