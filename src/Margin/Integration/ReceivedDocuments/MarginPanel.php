<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Integration\ReceivedDocuments;

use Foresi\Margin\Application\AllocationReads;
use Foresi\Margin\Application\LineAllocationView;
use Foresi\Margin\Application\ShareView;
use Foresi\Margin\Domain\MarginRefused;
use Foresi\ReceivedDocuments\Contract\PurchasingSlots;
use Foresi\Shared\Domain\Money;
use Foresi\Ui\Contract\Slot\SlotItem;
use Foresi\Ui\Contract\Slot\SlotProvider;
use Foresi\Ui\Format\MoneyText;

/**
 * The Margin panel of the page of a received document: who bears the cost of each line, how much, and where that
 * came from. Nothing is offered to a person who may not work with the margin, nor for a document Margin does not know.
 */
final readonly class MarginPanel implements SlotProvider
{
    public const string TEMPLATE = '@Margin/purchasing/_panel.html.twig';

    /** After the Plan panel of Cash flow (weight 10), before the history. */
    private const int WEIGHT = 20;

    public function __construct(private AllocationReads $reads, private MoneyText $text)
    {
    }

    public function items(string $slot, array $context): iterable
    {
        $documentId = $context['documentId'] ?? null;
        if (PurchasingSlots::DOCUMENT_SECTIONS !== $slot || !\is_string($documentId)) {
            return [];
        }
        try {
            $view = $this->reads->document($documentId);
        } catch (MarginRefused) {
            return [];
        }

        return [new SlotItem(self::TEMPLATE, [
            'whole' => null === $view->documentRecipient ? null : ['own' => 'own' === $view->documentRecipient, 'name' => $view->documentRecipientName],
            'gap' => [] !== $view->knownGaps,
            'lines' => array_map(fn (LineAllocationView $line): array => [
                'key' => $line->key,
                'text' => $line->text,
                'base' => $line->baseCzk instanceof Money ? $this->text->shown($line->baseCzk) : null,
                'shares' => array_map(fn (ShareView $share): array => [
                    'recipient' => $share->recipient,
                    'name' => $share->recipientName,
                    'amount' => $this->text->shown($share->amountCzk),
                    'source' => $share->source,
                ], $line->shares),
            ], $view->lines),
        ], self::WEIGHT)];
    }
}
