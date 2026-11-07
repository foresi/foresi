<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Controller;

use App\Identity\Application\OpenToExternal;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The start page of an external person: what the organisation shares with them.
 * Nothing can be shared yet, so it is a placeholder the projects module fills.
 */
#[OpenToExternal]
final class SharedWithMeController extends AbstractController
{
    public const string ROUTE = 'app_shared_with_me';

    #[Route('/shared', name: self::ROUTE, methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('identity/shared_with_me.html.twig');
    }
}
