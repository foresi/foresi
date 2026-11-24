<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Integration\CashFlow;

use Foresi\CashFlow\Contract\FlowDirection;
use Foresi\CashFlow\Contract\FlowSubject;
use Foresi\CashFlow\Contract\PairedOccurrence;
use Foresi\CashFlow\Contract\PairedSubject;
use Foresi\CashFlow\Contract\PlannedItemDirectory;
use Foresi\CashFlow\Contract\PlannedItemSummary;
use Foresi\CashFlow\Contract\PlannedPairings;
use Foresi\Margin\Application\CostPairing;
use Foresi\Margin\Application\PairingSource;
use Foresi\Margin\Application\PlannedCostItem;

/**
 * The pairings of received documents with Cash flow's planned items, for
 * Margin. Only auto and confirmed pairings count, as Cash flow reads them.
 * It reads the stored pairings, so it is used outside Cash flow's unit of
 * work: by Margin's own writes and by the asynchronous RecalculateOnPairing.
 */
final readonly class CashFlowPairings implements PairingSource
{
    /**
     * The subject type of Purchasing's received documents in Cash flow
     * (ReceivedDocumentPairables::TYPE, which Margin may not use).
     */
    public const string RECEIVED_DOCUMENT = 'received_document';

    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(private PlannedPairings $pairings, private PlannedItemDirectory $items)
    {
    }

    public function pairingOf(string $documentId): ?CostPairing
    {
        if (1 !== preg_match(self::UUID, $documentId)) {
            return null;
        }
        $paired = $this->pairings->of(new FlowSubject(self::RECEIVED_DOCUMENT, $documentId));

        return $paired instanceof PairedOccurrence ? new CostPairing($paired->plannedItemId, $paired->occurrenceOn) : null;
    }

    public function documentsOfOccurrence(string $plannedItemId, \DateTimeImmutable $occurrenceOn): array
    {
        if (1 !== preg_match(self::UUID, $plannedItemId)) {
            return [];
        }

        return $this->receivedDocuments($this->pairings->ofOccurrence($plannedItemId, $occurrenceOn));
    }

    public function documentsOfItemFrom(string $plannedItemId, \DateTimeImmutable $fromMonth): array
    {
        if (1 !== preg_match(self::UUID, $plannedItemId)) {
            return [];
        }
        $from = new \DateTimeImmutable($fromMonth->format('Y-m-01'));

        return $this->receivedDocuments(array_map(static fn (PairedSubject $paired): FlowSubject => $paired->subject, $this->pairings->ofItem($plannedItemId, $from)));
    }

    public function item(string $plannedItemId): ?PlannedCostItem
    {
        if (1 !== preg_match(self::UUID, $plannedItemId)) {
            return null;
        }
        $item = $this->items->one($plannedItemId);

        return $item instanceof PlannedItemSummary
            ? new PlannedCostItem($item->id, $item->name, FlowDirection::Out === $item->direction, $item->recurring, $item->counterpartyId, $item->url)
            : null;
    }

    public function costItems(): array
    {
        return array_map(static fn (PlannedItemSummary $item): PlannedCostItem => new PlannedCostItem($item->id, $item->name, true, $item->recurring, $item->counterpartyId, $item->url), $this->items->costs());
    }

    public function occurrenceHolding(string $plannedItemId, \DateTimeImmutable $day): ?\DateTimeImmutable
    {
        if (1 !== preg_match(self::UUID, $plannedItemId)) {
            return null;
        }

        return $this->items->occurrenceHolding($plannedItemId, $day);
    }

    public function occurrencesWithDocumentsFrom(string $plannedItemId, \DateTimeImmutable $fromDay): array
    {
        if (1 !== preg_match(self::UUID, $plannedItemId)) {
            return [];
        }
        $days = [];
        foreach ($this->pairings->ofItem($plannedItemId, $fromDay) as $paired) {
            if (self::RECEIVED_DOCUMENT === $paired->subject->type) {
                $days[$paired->occurrenceOn->format('Y-m-d')] = new \DateTimeImmutable($paired->occurrenceOn->format('Y-m-d'));
            }
        }
        ksort($days);

        return array_values($days);
    }

    /**
     * @param list<FlowSubject> $subjects
     *
     * @return list<string>
     */
    private function receivedDocuments(array $subjects): array
    {
        $ids = [];
        foreach ($subjects as $subject) {
            if (self::RECEIVED_DOCUMENT === $subject->type) {
                $ids[] = $subject->id;
            }
        }

        return $ids;
    }
}
