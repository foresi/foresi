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
use Foresi\Margin\Domain\Allocation;
use Foresi\Margin\Domain\CostShare;
use Foresi\Margin\Domain\DocumentAllocation;
use Foresi\Margin\Domain\LineAllocation;
use Foresi\Margin\Domain\MarginRefused;
use Foresi\Margin\Domain\PlannedAllocation;
use Foresi\Margin\Domain\SupplierDefault;
use Foresi\Party\Contract\PartyDirectory;
use Symfony\Component\Uid\Uuid;

/**
 * What the margin knows of one cost document: each effective line with its
 * own mapping, its shares and where each share came from, the mapping of the
 * whole document and the known gaps. The shares are the stored ones, so a
 * read is as current as the last write or document change. Also the
 * versions of a planned item's mapping and of a supplier's default.
 */
final readonly class AllocationReads
{
    public function __construct(
        private MarginAccess $access,
        private CostSources $sources,
        private KnownGaps $gaps,
        private EntityManagerInterface $entityManager,
        private PartyDirectory $contacts,
        private CostPairings $pairings,
        private InstanceClock $clock,
    ) {
    }

    /**
     * @throws MarginRefused forbidden, or unknown_planned_item
     */
    public function planned(string $plannedItemId): PlannedAllocationsView
    {
        $this->access->denyUnlessStaff();
        $item = $this->pairings->item($plannedItemId) ?? throw self::unknownPlannedItem();
        /** @var list<PlannedAllocation> $versions */
        $versions = $this->entityManager->getRepository(PlannedAllocation::class)->findBy(['plannedItemId' => $item->id], ['fromMonth' => 'DESC']);
        $current = $this->currentIndex(array_map(static fn (PlannedAllocation $version): \DateTimeImmutable => $version->fromMonth(), $versions));

        return new PlannedAllocationsView($item->id, $item->name, array_map(function (PlannedAllocation $version, int $index) use ($current): PlannedAllocationVersionView {
            $allocation = $version->allocation();

            return new PlannedAllocationVersionView($version->fromMonth()->format('Y-m'), $allocation instanceof Allocation ? $this->mapping($allocation) : null, $index === $current, $version->createdAt(), $version->createdBy());
        }, $versions, array_keys($versions)));
    }

    /**
     * @throws MarginRefused forbidden, or unknown_supplier
     */
    public function supplierDefault(string $contactId): SupplierDefaultsView
    {
        $this->access->denyUnlessStaff();
        $supplier = self::isUuid($contactId) ? $this->contacts->find($contactId) : null;
        if (!$supplier instanceof \Foresi\Party\Contract\PartySummary) {
            throw self::unknownSupplier();
        }
        /** @var list<SupplierDefault> $versions */
        $versions = $this->entityManager->getRepository(SupplierDefault::class)->findBy(['supplierId' => $supplier->id], ['fromMonth' => 'DESC']);
        $current = $this->currentIndex(array_map(static fn (SupplierDefault $version): \DateTimeImmutable => $version->fromMonth(), $versions));

        return new SupplierDefaultsView($supplier->id, $supplier->displayName, array_map(fn (SupplierDefault $version, int $index): SupplierDefaultVersionView => new SupplierDefaultVersionView(
            $version->fromMonth()->format('Y-m'),
            $version->recipient()?->toString(),
            $this->nameOf($version->recipient()?->contactId()),
            $index === $current,
        ), $versions, array_keys($versions)));
    }

    public static function unknownPlannedItem(): MarginRefused
    {
        return MarginRefused::notFound(MarginRefused::UNKNOWN_PLANNED_ITEM, 'Cash flow has no planned item with this id, or Cash flow is not installed. Find a planned cost with foresi_list_planned_items.');
    }

    public static function unknownSupplier(): MarginRefused
    {
        return MarginRefused::notFound(MarginRefused::UNKNOWN_SUPPLIER, 'The organisation has no active contact with this id to be a supplier. Find it with foresi_search_contacts.');
    }

    public static function isUuid(string $id): bool
    {
        return Uuid::isValid($id);
    }

    /**
     * @throws MarginRefused forbidden, or unknown_document
     */
    public function document(string $documentId): DocumentAllocationView
    {
        $this->access->denyUnlessStaff();

        return $this->view($this->sources->document($documentId) ?? throw self::unknownDocument());
    }

    public function view(CostDocument $document): DocumentAllocationView
    {
        $mappings = [];
        foreach ($this->entityManager->getRepository(LineAllocation::class)->findBy(['documentId' => $document->id]) as $stored) {
            $mappings[$stored->lineKey()] = $stored->allocation();
        }
        $shares = [];
        foreach ($this->entityManager->getRepository(CostShare::class)->findBy(['documentId' => $document->id], ['id' => 'ASC']) as $share) {
            $shares[$share->lineKey()][] = new ShareView($share->recipient()?->toString(), $this->nameOf($share->recipient()?->contactId()), $share->amount(), $share->source()->value, $share->plannedItemId(), $share->occurrenceOn()?->format('Y-m-d'));
        }
        $whole = $this->entityManager->getRepository(DocumentAllocation::class)->findOneBy(['documentId' => $document->id]);

        return new DocumentAllocationView(
            $document->id,
            $document->label,
            $document->url,
            $document->trashed,
            $whole?->recipient()->toString(),
            $this->nameOf($whole?->recipient()->contactId()),
            array_map(fn (CostLine $line): LineAllocationView => new LineAllocationView(
                $line->key,
                $line->text,
                $line->baseCzk,
                isset($mappings[$line->key]) ? $this->mapping($mappings[$line->key]) : null,
                $shares[$line->key] ?? [],
            ), $document->lines),
            $this->gaps->missingExchangeRate($document->id) ? [KnownGaps::MISSING_EXCHANGE_RATE] : [],
        );
    }

    public static function unknownDocument(): MarginRefused
    {
        return MarginRefused::notFound(MarginRefused::UNKNOWN_DOCUMENT, 'No recorded document with this id has costs, or it is a draft. Find a received document with foresi_search_received_documents.');
    }

    /**
     * The position of the version in force this month among versions sorted latest first, or null.
     *
     * @param list<\DateTimeImmutable> $fromMonths latest first
     */
    private function currentIndex(array $fromMonths): ?int
    {
        $month = $this->clock->today()->format('Y-m');
        foreach ($fromMonths as $index => $from) {
            if ($from->format('Y-m') <= $month) {
                return $index;
            }
        }

        return null;
    }

    private function mapping(Allocation $allocation): MappingView
    {
        return new MappingView($allocation->kind->value, array_map(fn (\Foresi\Margin\Domain\AllocationShare $share): TermView => new TermView(
            $share->recipient->toString(),
            $this->nameOf($share->recipient->contactId()),
            $share->percent,
            $share->amountCzk,
            $share->rest,
        ), $allocation->shares));
    }

    private function nameOf(?string $contactId): ?string
    {
        if (null === $contactId) {
            return null;
        }

        return $this->contacts->find($contactId)?->displayName;
    }
}
