<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Integration\CashFlow;

use Foresi\CashFlow\Contract\DocumentPairings;
use Foresi\CashFlow\Contract\FlowSubject;
use Foresi\CashFlow\Contract\PairingRefused;
use Foresi\Purchasing\Contract\PurchasingSlots;
use Foresi\Ui\Contract\Slot\SlotItem;
use Foresi\Ui\Contract\Slot\SlotProvider;

/**
 * The Plan panel of the page of a received document: which planned occurrence Cash flow paired the document
 * with, or proposes for it, and what a person can do about it.
 */
final readonly class PairingPanel implements SlotProvider
{
    public const string TEMPLATE = '@Purchasing/cash_flow/_pairing_panel.html.twig';

    /** After the figures of the document, before its history. */
    private const int WEIGHT = 10;

    public function __construct(private DocumentPairings $pairings)
    {
    }

    public function items(string $slot, array $context): iterable
    {
        $documentId = $context['documentId'] ?? null;
        if (PurchasingSlots::DOCUMENT_SECTIONS !== $slot || !\is_string($documentId)) {
            return [];
        }
        try {
            $view = $this->pairings->view(new FlowSubject(ReceivedDocumentPairables::TYPE, $documentId));
        } catch (PairingRefused) {
            return [];
        }

        return [new SlotItem(self::TEMPLATE, ['pairing' => $view, 'documentId' => $documentId], self::WEIGHT)];
    }
}
