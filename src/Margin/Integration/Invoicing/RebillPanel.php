<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Integration\Invoicing;

use Foresi\Invoicing\Contract\InvoicingSlots;
use Foresi\Margin\Application\RebillLinks;
use Foresi\Margin\Application\RebillPairings;
use Foresi\Margin\Domain\MarginRefused;
use Foresi\Margin\Infrastructure\Screen\MarginScreen;
use Foresi\Ui\Contract\Slot\SlotItem;
use Foresi\Ui\Contract\Slot\SlotProvider;

/**
 * What Margin adds to the lines of Invoicing's screens: on a recurring invoice the cost a line rebills (a badge and
 * "Rebills…" in its row menu), on an issued invoice the occurrence of a cost a line is paired with (a badge, "Pair with
 * a cost…" and "Unpair" in its row menu). Nothing is offered to a person who may not work with the margin.
 */
final readonly class RebillPanel implements SlotProvider
{
    public const string LINK_BADGE = '@Margin/invoicing/_link_badge.html.twig';
    public const string LINK_ACTION = '@Margin/invoicing/_link_action.html.twig';
    public const string PAIRING_BADGE = '@Margin/invoicing/_pairing_badge.html.twig';
    public const string PAIRING_ACTION = '@Margin/invoicing/_pairing_action.html.twig';

    public function __construct(private RebillLinks $links, private RebillPairings $pairings, private MarginScreen $screen)
    {
    }

    public function items(string $slot, array $context): iterable
    {
        $lineId = $context['lineId'] ?? null;
        if (!\is_string($lineId)) {
            return [];
        }
        try {
            return match ($slot) {
                InvoicingSlots::RECURRING_LINE_BADGES => $this->linkBadge($lineId),
                InvoicingSlots::RECURRING_LINE_ACTIONS => [new SlotItem(self::LINK_ACTION, ['lineId' => $lineId])],
                InvoicingSlots::INVOICE_LINE_BADGES => $this->pairingBadge($lineId),
                InvoicingSlots::INVOICE_LINE_ACTIONS => [new SlotItem(self::PAIRING_ACTION, ['lineId' => $lineId, 'paired' => [] !== $this->pairings->ofLines([$lineId])])],
                default => [],
            };
        } catch (MarginRefused) {
            return [];
        }
    }

    /**
     * @return list<SlotItem>
     */
    private function linkBadge(string $lineId): array
    {
        $link = $this->links->ofLine($lineId);

        return null === $link ? [] : [new SlotItem(self::LINK_BADGE, ['name' => $link->plannedItemName, 'warnings' => $link->warnings])];
    }

    /**
     * @return list<SlotItem>
     */
    private function pairingBadge(string $lineId): array
    {
        $pairing = $this->pairings->ofLines([$lineId])[$lineId] ?? null;

        return null === $pairing ? [] : [new SlotItem(self::PAIRING_BADGE, ['name' => $pairing->plannedItemName, 'month' => $this->screen->monthLabel(substr($pairing->occurrenceOn, 0, 7))])];
    }
}
