<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Integration\Ui\Controller;

use Foresi\Identity\Application\GuestCheck;
use Foresi\Identity\Application\OpenToGuest;
use Foresi\Identity\Contract\GuestHome;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
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
    /**
     * @param iterable<GuestHome> $guestHomes
     */
    public function __construct(
        private readonly GuestCheck $guest,
        #[AutowireIterator(GuestHome::TAG)]
        private readonly iterable $guestHomes,
    ) {
    }

    #[Route('/', name: 'app_dashboard', methods: ['GET'])]
    public function __invoke(): Response
    {
        if ($this->guest->isGuest()) {
            foreach ($this->guestHomes as $home) {
                return $this->redirectToRoute($home->route());
            }
        }

        return $this->render('@Identity/dashboard.html.twig');
    }
}
