<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Infrastructure\Controller;

use Foresi\Identity\Contract\StaffCheck;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Settings → Received documents: a plain host for the sections that modules add to it (PurchasingSlots::SETTINGS_SECTIONS),
 * such as the state of a reader. It has no settings of its own, so it says so when no module fills the slot.
 */
final class ReceivedDocumentsSettingsController extends AbstractController
{
    public const string ROUTE = 'app_received_documents_settings';

    public function __construct(private readonly StaffCheck $staff)
    {
    }

    #[Route('/settings/received-documents', name: self::ROUTE, methods: ['GET'])]
    public function index(): Response
    {
        if (!$this->staff->isStaff()) {
            throw new AccessDeniedHttpException('Only the owners and members of the organisation keep the settings of received documents.');
        }

        return $this->render('@Purchasing/settings.html.twig');
    }
}
