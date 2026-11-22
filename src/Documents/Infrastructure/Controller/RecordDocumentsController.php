<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Infrastructure\Controller;

use Foresi\Documents\Application\DocumentFilter;
use Foresi\Documents\Application\DocumentsAccess;
use Foresi\Documents\Application\DocumentsRefused;
use Foresi\Documents\Application\DocumentsStore;
use Foresi\Documents\Contract\RecordDocuments;
use Foresi\Documents\Infrastructure\Screen\DocumentScreen;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * The documents of one record as a fragment of another module's screen, such
 * as the Documents tab of a contact, the way Pipedrive lists a deal's documents
 * on its Documents tab: each with its type and status, opening the document,
 * and "New document" with the record's link filled in.
 *
 * Loaded by a Turbo frame (the request names it in its Turbo-Frame header, as
 * Party\Contract\ContactTabs::FRAME does), the answer is that frame alone;
 * without one the same address is a page of its own.
 */
final class RecordDocumentsController extends AbstractController
{
    /** The most documents the fragment lists, newest first. */
    private const int SHOWN = DocumentFilter::MAX_LIMIT;

    public function __construct(
        private readonly DocumentsAccess $access,
        private readonly DocumentsStore $store,
        private readonly DocumentScreen $screen,
    ) {
    }

    #[Route('/documents/for/{kind}/{id}', name: RecordDocuments::ROUTE, requirements: ['kind' => '[a-z0-9_-]{1,40}', 'id' => Requirement::UUID], methods: ['GET'])]
    public function forRecord(string $kind, string $id, Request $request): Response
    {
        try {
            $this->access->denyUnlessStaff();
        } catch (DocumentsRefused) {
            throw new AccessDeniedHttpException();
        }
        // A record kind is a lowercase identifier, as RecordLink names it.
        if (1 !== preg_match('/^[a-z][a-z0-9_]*$/', $kind)) {
            throw new NotFoundHttpException();
        }
        $documents = $this->store->documents(new DocumentFilter(linkKind: $kind, linkId: $id, limit: self::SHOWN));
        $frame = $request->headers->get('Turbo-Frame');

        return $this->render('@Documents/for_record.html.twig', [
            'frame' => \is_string($frame) && 1 === preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/', $frame) ? $frame : null,
            'kind' => $kind,
            'id' => $id,
            'columns' => $this->screen->columns(false),
            'rows' => $this->screen->rows($documents, false),
        ]);
    }
}
