<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Acme\Fleet\Infrastructure\Controller;

use Acme\Fleet\Application\Fleet;
use Acme\Fleet\Application\VehicleRefused;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The fleet's screen in the Ui shell, for the owners and members only.
 */
final class VehicleController extends AbstractController
{
    public const string ROUTE = 'acme_fleet_vehicles';

    public function __construct(private readonly Fleet $fleet)
    {
    }

    #[Route('/acme-fleet/vehicles', name: self::ROUTE, methods: ['GET'])]
    public function index(): Response
    {
        try {
            $vehicles = $this->fleet->all();
        } catch (VehicleRefused $refused) {
            throw new AccessDeniedHttpException($refused->getMessage(), $refused);
        }

        return $this->render('@AcmeFleet/vehicles/index.html.twig', ['vehicles' => array_map(Fleet::json(...), $vehicles)]);
    }
}
