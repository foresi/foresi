<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Application;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Margin\Contract\CostDocument;
use Foresi\Margin\Contract\CostLine;
use Foresi\Margin\Domain\Allocation;
use Foresi\Margin\Domain\CostShare;
use Foresi\Margin\Domain\DocumentAllocation;
use Foresi\Margin\Domain\LineAllocation;
use Foresi\Margin\Domain\MarginRefused;
use Foresi\Party\Contract\PartyDirectory;

/**
 * What the margin knows of one cost document: each effective line with its
 * own mapping, its shares and where each share came from, the mapping of the
 * whole document and the known gaps. The shares are the stored ones, so a
 * read is as current as the last write or document change.
 */
final readonly class AllocationReads
{
    public function __construct(
        private MarginAccess $access,
        private CostSources $sources,
        private KnownGaps $gaps,
        private EntityManagerInterface $entityManager,
        private PartyDirectory $contacts,
    ) {
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
            $shares[$share->lineKey()][] = new ShareView($share->recipient()?->toString(), $this->nameOf($share->recipient()?->contactId()), $share->amount(), $share->source()->value);
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
