<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Application;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Margin\Contract\CostDocument;
use Foresi\Margin\Contract\CostLine;
use Foresi\Margin\Contract\CostSharesRecalculation;
use Foresi\Margin\Domain\Allocation;
use Foresi\Margin\Domain\AllocationKind;
use Foresi\Margin\Domain\AllocationShare;
use Foresi\Margin\Domain\CostShare;
use Foresi\Margin\Domain\CostShareSource;
use Foresi\Margin\Domain\DocumentAllocation;
use Foresi\Margin\Domain\LineAllocation;
use Foresi\Margin\Domain\Recipient;
use Foresi\Margin\Domain\ShareSplitter;
use Foresi\Shared\Domain\Money;
use Symfony\Component\Uid\Uuid;

/**
 * Replaces the shares of a document with what its mappings say now. Each
 * line goes, in this order, to:
 *
 * 1. its own mapping;
 * 2. the mapping of the document;
 * 3. the mapping of the planned item the document is paired with, the
 *    version in force in the occurrence's month;
 * 4. the default of the document's supplier in force in the cost's month,
 *    whole to one recipient;
 * 5. nobody: an unassigned share, so no cost is silent.
 *
 * The month of a cost is the month of its occurrence for a paired document,
 * else the month of its month basis in the instance zone. A fixed mapping of
 * a planned item is filled per occurrence (OccurrenceFill), so recalculating
 * a paired document recalculates every document of its occurrence, and of
 * the occurrence its shares came from before, if it moved.
 *
 * A trashed or missing document has no shares, and its mapping stays for a
 * restore. A line without a base in CZK has no share; KnownGaps reports it.
 * Mappings of lines that are gone are dropped, as a line keeps its key while
 * it exists.
 *
 * It changes the entity manager only; whoever calls it flushes with the
 * change that made it necessary.
 */
final readonly class CostShareCalculation implements CostSharesRecalculation
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CostSources $sources,
        private ShareSplitter $splitter,
        private InstanceClock $clock,
        private CostPairings $pairings,
        private MappingVersions $versions,
        private OccurrenceFill $fill,
    ) {
    }

    public function recalculate(string $documentId): void
    {
        $this->recalculateAll([$documentId]);
    }

    /**
     * Recalculates each document and every document that shares an occurrence with it, each once.
     *
     * @param iterable<string> $documentIds
     */
    public function recalculateAll(iterable $documentIds): void
    {
        $done = [];
        $fills = [];
        foreach ($documentIds as $documentId) {
            foreach ($this->withTheirOccurrences($documentId) as $id) {
                if (!isset($done[$id])) {
                    $done[$id] = true;
                    $this->calculate($id, $fills);
                }
            }
        }
    }

    /**
     * The first day of the month a document's cost falls in: that of its
     * occurrence when it is paired, else that of its month basis. Null for a
     * document no source has.
     */
    public function costMonthOf(string $documentId): ?\DateTimeImmutable
    {
        $document = $this->sources->document($documentId);
        if (!$document instanceof CostDocument) {
            return null;
        }
        $pairing = $this->pairings->of($documentId);

        return $pairing instanceof CostPairing ? $pairing->month() : $this->monthOf($document);
    }

    /**
     * The document, then the documents of the occurrence it is paired with,
     * then those of the occurrences its present shares came from.
     *
     * @return list<string>
     */
    private function withTheirOccurrences(string $documentId): array
    {
        $ids = [$documentId];
        $pairing = $this->pairings->of($documentId);
        $occurrences = $pairing instanceof CostPairing ? [$pairing] : [];
        // Shares already scheduled for removal still name the occurrence the document came from: a
        // superset of occurrences is only extra work, a missed one would leave a fill stale.
        foreach ($this->sharesOf($documentId) as $share) {
            $itemId = $share->plannedItemId();
            $on = $share->occurrenceOn();
            if (null !== $itemId && $on instanceof \DateTimeImmutable) {
                $occurrences[] = new CostPairing($itemId, $on);
            }
        }
        foreach ($occurrences as $occurrence) {
            $ids = [...$ids, ...$this->pairings->documentsOfOccurrence($occurrence->plannedItemId, $occurrence->day())];
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param array<string, array<string, array<string, array<string, list<array{Recipient, Money}>>>>> $fills the fixed fills of the occurrences met so far, by planned item and day
     */
    private function calculate(string $documentId, array &$fills): void
    {
        $this->forgetShares($documentId);
        $document = $this->sources->document($documentId);
        if (!$document instanceof CostDocument || $document->trashed) {
            return;
        }

        $lineMappings = $this->lineMappings($document, drop: true);
        $wholeDocument = $this->documentMapping($documentId);
        $pairing = $this->pairings->of($documentId);
        $month = $pairing instanceof CostPairing ? $pairing->month() : $this->monthOf($document);
        $planned = $pairing instanceof CostPairing ? $this->versions->planned($pairing->plannedItemId, $month) : null;
        $supplier = null === $document->supplierId ? null : $this->versions->supplierDefault($document->supplierId, $month);

        foreach ($document->lines as $line) {
            if (!$line->baseCzk instanceof Money) {
                continue;
            }
            $mapping = $lineMappings[$line->key] ?? null;
            if ($mapping instanceof Allocation) {
                $this->split($document, $line, $month, $mapping, CostShareSource::Line);
            } elseif ($wholeDocument instanceof Recipient) {
                $this->split($document, $line, $month, $this->whole($wholeDocument), CostShareSource::Document);
            } elseif ($pairing instanceof CostPairing && $planned instanceof Allocation) {
                $parts = AllocationKind::Fixed === $planned->kind
                    ? $this->fixedParts($document, $pairing, $planned, $fills)[$document->id][$line->key] ?? []
                    : $this->splitter->split($planned, $line->baseCzk);
                foreach ($parts as [$recipient, $amount]) {
                    $this->add($document->id, $line->key, $month, $recipient->toString(), $amount, CostShareSource::PlannedItem, $pairing);
                }
            } elseif ($supplier instanceof Recipient) {
                $this->split($document, $line, $month, $this->whole($supplier), CostShareSource::Supplier);
            } else {
                $this->add($document->id, $line->key, $month, null, $line->baseCzk, CostShareSource::Unassigned);
            }
        }
    }

    /**
     * The fill of a fixed mapping over every document of the occurrence of the paired document, by document id and line key.
     *
     * @param array<string, array<string, array<string, array<string, list<array{Recipient, Money}>>>>> $fills
     *
     * @return array<string, array<string, list<array{Recipient, Money}>>>
     */
    private function fixedParts(CostDocument $paired, CostPairing $pairing, Allocation $planned, array &$fills): array
    {
        $day = $pairing->day()->format('Y-m-d');
        if (isset($fills[$pairing->plannedItemId][$day][$paired->id])) {
            return $fills[$pairing->plannedItemId][$day];
        }
        $documents = [];
        // The document itself is of its occurrence even if the source has not listed it yet.
        foreach (array_unique([$paired->id, ...$this->pairings->documentsOfOccurrence($pairing->plannedItemId, $pairing->day())]) as $id) {
            $document = $this->sources->document($id);
            if ($document instanceof CostDocument && !$document->trashed) {
                $documents[] = [$document, $this->linesOfTheItem($document)];
            }
        }

        return $fills[$pairing->plannedItemId][$day] = $this->fill->split($planned, $documents);
    }

    /**
     * The lines of a document that its paired item's mapping applies to: those
     * with a base and no mapping of their own, in a document without a mapping.
     *
     * @return list<CostLine>
     */
    private function linesOfTheItem(CostDocument $document): array
    {
        if ($this->documentMapping($document->id) instanceof Recipient) {
            return [];
        }
        $mappings = $this->lineMappings($document, drop: false);

        return array_values(array_filter($document->lines, static fn (CostLine $line): bool => $line->baseCzk instanceof Money && !isset($mappings[$line->key])));
    }

    private function split(CostDocument $document, CostLine $line, \DateTimeImmutable $month, Allocation $allocation, CostShareSource $source): void
    {
        if (!$line->baseCzk instanceof Money) {
            return;
        }
        foreach ($this->splitter->split($allocation, $line->baseCzk) as [$recipient, $amount]) {
            $this->add($document->id, $line->key, $month, $recipient->toString(), $amount, $source);
        }
    }

    private function whole(Recipient $recipient): Allocation
    {
        return new Allocation(AllocationKind::Whole, [new AllocationShare($recipient)]);
    }

    private function documentMapping(string $documentId): ?Recipient
    {
        return $this->entityManager->getRepository(DocumentAllocation::class)->findOneBy(['documentId' => $documentId])?->recipient();
    }

    /**
     * The mappings of the lines the document has; with drop, the others are removed.
     *
     * @return array<string, Allocation>
     */
    private function lineMappings(CostDocument $document, bool $drop): array
    {
        $keys = array_map(static fn (CostLine $line): string => $line->key, $document->lines);
        $mappings = [];
        foreach ($this->entityManager->getRepository(LineAllocation::class)->findBy(['documentId' => $document->id]) as $stored) {
            if (!\in_array($stored->lineKey(), $keys, true)) {
                if ($drop) {
                    $this->entityManager->remove($stored);
                }
                continue;
            }
            $mappings[$stored->lineKey()] = $stored->allocation();
        }

        return $mappings;
    }

    private function monthOf(CostDocument $document): \DateTimeImmutable
    {
        return MappingVersions::monthOf($document->monthBasis->setTimezone($this->clock->timezone()));
    }

    private function add(string $documentId, string $lineKey, \DateTimeImmutable $month, ?string $recipient, Money $amount, CostShareSource $source, ?CostPairing $pairing = null): void
    {
        if (0 === $amount->minor()) {
            return;
        }
        $this->entityManager->persist(new CostShare(Uuid::v7()->toRfc4122(), $documentId, $lineKey, $month, $recipient, $amount->minor(), $source, $pairing?->plannedItemId, $pairing?->day()));
    }

    /**
     * The shares stored for the document and the ones this unit of work has not stored yet.
     *
     * @return list<CostShare>
     */
    private function sharesOf(string $documentId): array
    {
        $shares = $this->entityManager->getRepository(CostShare::class)->findBy(['documentId' => $documentId]);
        foreach ($this->entityManager->getUnitOfWork()->getScheduledEntityInsertions() as $pending) {
            if ($pending instanceof CostShare && $pending->documentId() === $documentId) {
                $shares[] = $pending;
            }
        }

        return $shares;
    }

    /**
     * Removes the stored shares and the ones this unit of work has not stored yet.
     */
    private function forgetShares(string $documentId): void
    {
        foreach ($this->entityManager->getUnitOfWork()->getScheduledEntityInsertions() as $pending) {
            if ($pending instanceof CostShare && $pending->documentId() === $documentId) {
                $this->entityManager->detach($pending);
            }
        }
        foreach ($this->entityManager->getRepository(CostShare::class)->findBy(['documentId' => $documentId]) as $stored) {
            $this->entityManager->remove($stored);
        }
    }
}
