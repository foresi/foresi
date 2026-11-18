<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Infrastructure\Controller;

use Foresi\Identity\Application\GuestCheck;
use Foresi\Identity\Application\OpenToGuest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The start page: the sections the modules contribute (Foresi\Ui\Dashboard), or a
 * placeholder until there is data. A guest starts on what is shared
 * with them instead, so the sign-in lands there too; the work module serves
 * that page.
 */
#[OpenToGuest]
final class DashboardController extends AbstractController
{
    /** The start page of a guest: what the organisation shares with them. */
    public const string GUEST_HOME = 'app_shared_with_me';

    public function __construct(private readonly GuestCheck $guest)
    {
    }

    #[Route('/', name: 'app_dashboard', methods: ['GET'])]
    public function __invoke(): Response
    {
        if ($this->guest->isGuest()) {
            return $this->redirectToRoute(self::GUEST_HOME);
        }

        return $this->render('identity/dashboard.html.twig');
    }
}
