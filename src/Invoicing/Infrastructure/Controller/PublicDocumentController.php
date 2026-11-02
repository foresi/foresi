<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The address a sent mail links to. It shows nothing yet.
 */
final class PublicDocumentController
{
    #[Route('/d/{token}', name: 'app_document_link', requirements: ['token' => '[^/]{1,200}'], methods: ['GET'])]
    public function page(): Response
    {
        return new Response('', Response::HTTP_NOT_FOUND);
    }
}
