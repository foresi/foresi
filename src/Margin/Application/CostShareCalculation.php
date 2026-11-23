<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Application;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Margin\Contract\CostDocument;
use Foresi\Margin\Contract\CostSharesRecalculation;
use Foresi\Margin\Domain\Allocation;
use Foresi\Margin\Domain\AllocationKind;
use Foresi\Margin\Domain\AllocationShare;
use Foresi\Margin\Domain\CostShare;
use Foresi\Margin\Domain\CostShareSource;
use Foresi\Margin\Domain\DocumentAllocation;
use Foresi\Margin\Domain\LineAllocation;
use Foresi\Margin\Domain\ShareSplitter;
use Foresi\Shared\Domain\Money;
use Symfony\Component\Uid\Uuid;

/**
 * Replaces the shares of a document with what its mapping says now. A line
 * goes to its own mapping, else to the mapping of the document, else nowhere
 * (an unassigned share, so no cost is silent). A trashed or missing document
 * has no shares, and its mapping stays for a restore. A line without a base
 * in CZK has no share; KnownGaps reports it. Mappings of lines that are gone
 * are dropped, as a line keeps its key while it exists.
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
    ) {
    }

    public function recalculate(string $documentId): void
    {
        $this->forgetShares($documentId);
        $document = $this->sources->document($documentId);
        if (!$document instanceof CostDocument || $document->trashed) {
            return;
        }

        $lineMappings = $this->lineMappings($document);
        $wholeDocument = $this->entityManager->getRepository(DocumentAllocation::class)->findOneBy(['documentId' => $documentId]);
        $month = $this->monthOf($document);
        foreach ($document->lines as $line) {
            if (!$line->baseCzk instanceof Money) {
                continue;
            }
            $mapping = $lineMappings[$line->key] ?? null;
            if ($mapping instanceof Allocation) {
                $allocation = $mapping;
                $source = CostShareSource::Line;
            } elseif ($wholeDocument instanceof DocumentAllocation) {
                $allocation = new Allocation(AllocationKind::Whole, [new AllocationShare($wholeDocument->recipient())]);
                $source = CostShareSource::Document;
            } else {
                $this->add($documentId, $line->key, $month, null, $line->baseCzk, CostShareSource::Unassigned);
                continue;
            }
            foreach ($this->splitter->split($allocation, $line->baseCzk) as [$recipient, $amount]) {
                $this->add($documentId, $line->key, $month, $recipient->toString(), $amount, $source);
            }
        }
    }

    /**
     * The mappings of the lines the document has; the others are dropped.
     *
     * @return array<string, Allocation>
     */
    private function lineMappings(CostDocument $document): array
    {
        $keys = array_map(static fn (\Foresi\Margin\Contract\CostLine $line): string => $line->key, $document->lines);
        $mappings = [];
        foreach ($this->entityManager->getRepository(LineAllocation::class)->findBy(['documentId' => $document->id]) as $stored) {
            if (!\in_array($stored->lineKey(), $keys, true)) {
                $this->entityManager->remove($stored);
                continue;
            }
            $mappings[$stored->lineKey()] = $stored->allocation();
        }

        return $mappings;
    }

    private function monthOf(CostDocument $document): \DateTimeImmutable
    {
        $day = $document->monthBasis->setTimezone($this->clock->timezone());

        return new \DateTimeImmutable($day->format('Y-m-01'));
    }

    private function add(string $documentId, string $lineKey, \DateTimeImmutable $month, ?string $recipient, Money $amount, CostShareSource $source): void
    {
        if (0 === $amount->minor()) {
            return;
        }
        $this->entityManager->persist(new CostShare(Uuid::v7()->toRfc4122(), $documentId, $lineKey, $month, $recipient, $amount->minor(), $source));
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
