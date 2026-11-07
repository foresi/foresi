<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Controller;

use App\Identity\Application\ExternalCheck;
use App\Identity\Application\OpenToExternal;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The start page: the sections the modules contribute (App\Ui\Dashboard), or a
 * placeholder until there is data. An external person starts on what is shared
 * with them instead, so the sign-in lands there too.
 */
#[OpenToExternal]
final class DashboardController extends AbstractController
{
    public function __construct(private readonly ExternalCheck $external)
    {
    }

    #[Route('/', name: 'app_dashboard', methods: ['GET'])]
    public function __invoke(): Response
    {
        if ($this->external->isExternal()) {
            return $this->redirectToRoute(SharedWithMeController::ROUTE);
        }

        return $this->render('identity/dashboard.html.twig');
    }
}
