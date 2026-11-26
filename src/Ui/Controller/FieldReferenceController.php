<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Controller;

use Foresi\Shared\Application\ActorRole;
use Foresi\Shared\Application\Field\FieldReferenceNames;
use Foresi\Shared\Application\Field\ReferenceNames;
use Foresi\Shared\Contract\Field\FieldKind;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The picker of a contact or a user field of the custom fields (ADR 0045), as Ui:Combobox
 * and the filter sheet read it: the records whose name matches the typed text. Only the
 * staff pick; a guest sees no reference to pick.
 */
final class FieldReferenceController extends AbstractController
{
    /** At most this many matches. */
    public const int LIMIT = 10;
    private const int MAX_QUERY = 100;

    public function __construct(private readonly ReferenceNames $names, private readonly ActorRole $actor)
    {
    }

    #[Route('/fields/{kind}/matches', name: FieldReferenceNames::ROUTE, requirements: ['kind' => 'contact|user'], methods: ['GET'])]
    public function matches(string $kind, Request $request): JsonResponse
    {
        if (!$this->actor->isStaff()) {
            throw new AccessDeniedHttpException('Only the staff pick a reference.');
        }
        $names = $this->names->of(FieldKind::from($kind)) ?? throw new NotFoundHttpException(\sprintf('No installed module names the %s records.', $kind));

        return new JsonResponse(['results' => $names->matches(mb_substr($request->query->getString('q'), 0, self::MAX_QUERY), self::LIMIT)]);
    }
}
