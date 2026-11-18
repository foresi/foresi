<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Timesheet\Infrastructure\Slot;

use Foresi\Timesheet\Infrastructure\Controller\TimeInvoiceController;
use Foresi\Ui\Slot\SlotItem;
use Foresi\Ui\Slot\SlotProvider;
use Foresi\Work\Contract\TimeBillingActions;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * "Invoice time" on a project and on the time report, opening the time invoice dialog for the
 * project or the client of the report's filter.
 */
final readonly class TimeInvoiceActions implements SlotProvider
{
    public function __construct(private UrlGeneratorInterface $urls)
    {
    }

    public function items(string $slot, array $context): iterable
    {
        return match ($slot) {
            TimeBillingActions::PROJECT => \is_string($context['projectId'] ?? null)
                ? [new SlotItem('@Timesheet/_project_action.html.twig', ['href' => $this->urls->generate(TimeInvoiceController::ROUTE, ['project' => $context['projectId']])])]
                : [],
            TimeBillingActions::REPORT => [new SlotItem('@Timesheet/_report_action.html.twig', ['href' => $this->urls->generate(
                TimeInvoiceController::ROUTE,
                \is_string($context['clientId'] ?? null) ? ['client' => $context['clientId']] : [],
            )])],
            default => [],
        };
    }
}
