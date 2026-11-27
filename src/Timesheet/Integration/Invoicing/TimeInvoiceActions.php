<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Timesheet\Integration\Invoicing;

use Foresi\Identity\Application\OwnerCheck;
use Foresi\Timesheet\Infrastructure\Slot\TimeBillingActions;
use Foresi\Ui\Contract\Slot\SlotItem;
use Foresi\Ui\Contract\Slot\SlotProvider;
use Foresi\Work\Contract\ProjectSlots;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * "Invoice time" in the "⋯" menu of a project, for an owner only because only
 * an owner sees rates, and on the time report, opening the time invoice dialog
 * for the project or the client of the report's filter.
 */
final readonly class TimeInvoiceActions implements SlotProvider
{
    public function __construct(private UrlGeneratorInterface $urls, private OwnerCheck $owner)
    {
    }

    public function items(string $slot, array $context): iterable
    {
        return match ($slot) {
            ProjectSlots::HEADER_ACTIONS => \is_string($context['projectId'] ?? null) && $this->owner->isOwner()
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
