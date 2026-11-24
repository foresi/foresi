<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Application;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Margin\Contract\RevenueDocument;
use Foresi\Margin\Domain\RebillLink;
use Foresi\Margin\Domain\RebillPairing;
use Symfony\Component\Uid\Uuid;

/**
 * Pairs the lines of a newly issued invoice with the occurrences they rebill:
 * a line issued from a template line that has a rebill link is paired with the
 * occurrence of the link's item that holds the invoice's tax point, when the
 * customer of the invoice bears a share of the item's mapping in that
 * occurrence's month. Otherwise nothing is paired, and what is left shows as
 * unrebilled. Idempotent: a line that is paired is left as it is.
 *
 * Runs for the asynchronous listener of Invoicing's event, in the unit of work
 * of the delivery, which flushes it; it persists and leaves the flush to it.
 * Read the invoice before changing anything: its source reads the stored
 * invoice and may refuse a unit of work with changes.
 */
final readonly class PairRebillOnIssue
{
    public function __construct(
        private RevenueSources $revenue,
        private CostPairings $pairings,
        private MappingVersions $mappings,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return int how many lines were paired
     */
    public function pair(string $invoiceId): int
    {
        // Reading the document starts the session over, so it is read only when some line rebills at all.
        if (0 === $this->entityManager->getRepository(RebillLink::class)->count([])) {
            return 0;
        }
        $document = $this->revenue->document($invoiceId);
        if (!$document instanceof RevenueDocument) {
            return 0;
        }
        // The tax point is a day in the instance's zone, as Cash flow reads it; it is passed on as it is.
        $day = $document->taxPointOn;
        $paired = 0;
        foreach ($document->lines as $line) {
            if (null === $line->sourceLineId) {
                continue;
            }
            $link = $this->entityManager->getRepository(RebillLink::class)->findOneBy(['recurringLineId' => $line->sourceLineId]);
            if (!$link instanceof RebillLink || $this->entityManager->getRepository(RebillPairing::class)->findOneBy(['invoiceLineId' => $line->id]) instanceof RebillPairing) {
                continue;
            }
            $occurrence = $this->pairings->occurrenceHolding($link->plannedItemId(), $day);
            if (!$occurrence instanceof \DateTimeImmutable || !\in_array(strtolower($document->customerId), $this->mappings->plannedClients($link->plannedItemId(), $occurrence), true)) {
                continue;
            }
            $this->entityManager->persist(new RebillPairing(Uuid::v7()->toRfc4122(), $line->id, $document->id, strtolower($document->customerId), $link->plannedItemId(), new \DateTimeImmutable($occurrence->format('Y-m-d')), false));
            ++$paired;
        }

        return $paired;
    }
}
