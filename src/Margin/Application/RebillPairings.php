<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Application;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Margin\Contract\RevenueDocument;
use Foresi\Margin\Domain\MarginRefused;
use Foresi\Margin\Domain\RebillPairing;
use Foresi\Shared\Application\TransactionalWork;
use Symfony\Component\Uid\Uuid;

/**
 * What a person says about what an invoice line rebills: a line, typically a
 * hand-written one, is paired with an occurrence of a planned item, or its
 * pairing, made by hand or on issue, is taken away. A pick the customer has no
 * share in is told, not stored. Staff only.
 */
final readonly class RebillPairings
{
    public function __construct(
        private MarginAccess $access,
        private RevenueSources $revenue,
        private CostPairings $pairings,
        private MappingVersions $mappings,
        private EntityManagerInterface $entityManager,
        private TransactionalWork $transaction,
        private InstanceClock $clock,
    ) {
    }

    /**
     * @param string $occurrenceOn YYYY-MM-DD, any day of the period of an occurrence of the item
     *
     * @throws MarginRefused forbidden, unknown_invoice_line, unknown_planned_item, not_an_expense, invalid_day, not_an_occurrence, already_paired or customer_not_a_recipient
     */
    public function pair(string $invoiceLineId, string $plannedItemId, string $occurrenceOn): RebillPairingView
    {
        $this->access->denyUnlessStaff();
        // The invoice is read before anything is changed.
        $document = $this->revenue->documentOfLine($invoiceLineId);
        if (!$document instanceof RevenueDocument) {
            throw MarginRefused::notFound(MarginRefused::UNKNOWN_INVOICE_LINE, 'There is no issued invoice line with this id.');
        }
        $item = $this->pairings->item($plannedItemId) ?? throw AllocationReads::unknownPlannedItem();
        if (!$item->expense) {
            throw MarginRefused::invalid(MarginRefused::NOT_AN_EXPENSE, 'plannedItemId', 'Only a planned cost, money going out, is rebilled.');
        }
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $occurrenceOn, $this->clock->timezone());
        if (!$day instanceof \DateTimeImmutable || $day->format('Y-m-d') !== $occurrenceOn) {
            throw MarginRefused::invalid(MarginRefused::INVALID_DAY, 'occurrenceOn', 'A day is written YYYY-MM-DD, for example 2026-11-01.');
        }
        $holding = $this->pairings->occurrenceHolding($item->id, $day);
        if (!$holding instanceof \DateTimeImmutable) {
            throw MarginRefused::invalid(MarginRefused::NOT_AN_OCCURRENCE, 'occurrenceOn', 'The planned item has no occurrence whose period holds this day.');
        }
        // Any day of the period of an occurrence names it, as the tax point does on issue: the occurrence itself is stored.
        $day = $holding;
        if ($this->entityManager->getRepository(RebillPairing::class)->findOneBy(['invoiceLineId' => $invoiceLineId]) instanceof RebillPairing) {
            throw MarginRefused::invalid(MarginRefused::ALREADY_PAIRED, 'invoiceLineId', 'The line already rebills an occurrence. Unpair it first.');
        }
        if (!\in_array(strtolower($document->customerId), $this->mappings->plannedClients($item->id, $day), true)) {
            throw MarginRefused::invalid(MarginRefused::CUSTOMER_NOT_A_RECIPIENT, 'invoiceLineId', 'The customer of the invoice bears no share of the planned item in the month of this occurrence.');
        }

        $this->transaction->run(function () use ($invoiceLineId, $document, $item, $day): void {
            $this->entityManager->persist(new RebillPairing(Uuid::v7()->toRfc4122(), $invoiceLineId, $document->id, strtolower($document->customerId), $item->id, $day, true));
            $this->entityManager->flush();
        });

        return new RebillPairingView($invoiceLineId, $document->id, $item->id, $item->name, $item->url, $day->format('Y-m-d'), true);
    }

    /**
     * What the lines of an invoice rebill, for a screen that shows it.
     *
     * @param list<string> $invoiceLineIds
     *
     * @return array<string, RebillPairingView> by the id of the line, only the lines that are paired
     *
     * @throws MarginRefused forbidden
     */
    public function ofLines(array $invoiceLineIds): array
    {
        $this->access->denyUnlessStaff();
        /** @var list<RebillPairing> $stored */
        $stored = $this->entityManager->getRepository(RebillPairing::class)->findBy(['invoiceLineId' => $invoiceLineIds]);
        $views = [];
        foreach ($stored as $pairing) {
            $item = $this->pairings->item($pairing->plannedItemId());
            $views[$pairing->invoiceLineId()] = new RebillPairingView($pairing->invoiceLineId(), $pairing->invoiceId(), $pairing->plannedItemId(), $item->name ?? $pairing->plannedItemId(), $item?->url, $pairing->occurrenceOn()->format('Y-m-d'), $pairing->manual());
        }

        return $views;
    }

    /**
     * @throws MarginRefused forbidden or unknown_pairing
     */
    public function unpair(string $invoiceLineId): void
    {
        $this->access->denyUnlessStaff();
        $stored = $this->entityManager->getRepository(RebillPairing::class)->findOneBy(['invoiceLineId' => $invoiceLineId])
            ?? throw MarginRefused::notFound(MarginRefused::UNKNOWN_PAIRING, 'This invoice line is not paired with an occurrence.');
        $this->transaction->run(function () use ($stored): void {
            $this->entityManager->remove($stored);
            $this->entityManager->flush();
        });
    }
}
