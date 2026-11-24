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
use Symfony\Contracts\Service\ResetInterface;

/**
 * The pairings of received documents with Cash flow's planned items, for
 * Margin. Only auto and confirmed pairings count, as Cash flow reads them.
 *
 * Cash flow records DocumentPairingChanged before its unit of work is
 * flushed, so while it is still working its stored pairings lag behind. A
 * pairing an event told of is remembered and wins over the stored one until
 * the next request or message; that is what the event made true.
 */
final class CashFlowPairings implements PairingSource, ResetInterface
{
    /**
     * The subject type of Purchasing's received documents in Cash flow
     * (ReceivedDocumentPairables::TYPE, which Margin may not use).
     */
    public const string RECEIVED_DOCUMENT = 'received_document';

    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /** @var array<string, CostPairing|null> what the events told, by document id */
    private array $told = [];

    public function __construct(private readonly PlannedPairings $pairings, private readonly PlannedItemDirectory $items)
    {
    }

    /**
     * Remembers where an event says the document is paired now, null for nowhere that counts.
     */
    public function remember(string $documentId, ?CostPairing $pairing): void
    {
        $this->told[$documentId] = $pairing;
    }

    public function reset(): void
    {
        $this->told = [];
    }

    public function pairingOf(string $documentId): ?CostPairing
    {
        if (\array_key_exists($documentId, $this->told)) {
            return $this->told[$documentId];
        }
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
        $ids = $this->receivedDocuments($this->pairings->ofOccurrence($plannedItemId, $occurrenceOn));

        return $this->corrected($ids, static fn (CostPairing $pairing): bool => $pairing->plannedItemId === $plannedItemId && $pairing->day()->format('Y-m-d') === $occurrenceOn->format('Y-m-d'));
    }

    public function documentsOfItemFrom(string $plannedItemId, \DateTimeImmutable $fromMonth): array
    {
        if (1 !== preg_match(self::UUID, $plannedItemId)) {
            return [];
        }
        $from = new \DateTimeImmutable($fromMonth->format('Y-m-01'));
        $ids = $this->receivedDocuments(array_map(static fn (PairedSubject $paired): FlowSubject => $paired->subject, $this->pairings->ofItem($plannedItemId, $from)));

        return $this->corrected($ids, static fn (CostPairing $pairing): bool => $pairing->plannedItemId === $plannedItemId && $pairing->day() >= $from);
    }

    public function item(string $plannedItemId): ?PlannedCostItem
    {
        if (1 !== preg_match(self::UUID, $plannedItemId)) {
            return null;
        }
        $item = $this->items->one($plannedItemId);

        return $item instanceof PlannedItemSummary
            ? new PlannedCostItem($item->id, $item->name, FlowDirection::Out === $item->direction, $item->recurring, $item->counterpartyId)
            : null;
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

    /**
     * The stored ids with what the events told: a document told elsewhere is left out, one told here is added.
     *
     * @param list<string>                $ids
     * @param callable(CostPairing): bool $here
     *
     * @return list<string>
     */
    private function corrected(array $ids, callable $here): array
    {
        $ids = array_filter($ids, fn (string $id): bool => !\array_key_exists($id, $this->told) || ($this->told[$id] instanceof CostPairing && $here($this->told[$id])));
        foreach ($this->told as $id => $pairing) {
            if ($pairing instanceof CostPairing && $here($pairing)) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }
}
