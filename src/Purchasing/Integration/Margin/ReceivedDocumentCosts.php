<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Integration\Margin;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Margin\Contract\CostDocument;
use Foresi\Margin\Contract\CostLine;
use Foresi\Margin\Contract\MarginCostSource;
use Foresi\Purchasing\Domain\LineView;
use Foresi\Purchasing\Domain\ReceivedDocument;
use Foresi\Purchasing\Domain\ReceivedDocumentState;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The costs of the received documents, line by line, for Margin. A line is
 * keyed by its id (or by its rate while the document has no lines) and its
 * base is in CZK without VAT, null while a foreign document has no rate. The
 * month of a document is that of its tax point, else of its issue date. A
 * draft has no costs yet, so it is not offered.
 */
final readonly class ReceivedDocumentCosts implements MarginCostSource
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UrlGeneratorInterface $urls,
        private InstanceClock $clock,
    ) {
    }

    public function document(string $id): ?CostDocument
    {
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id)) {
            return null;
        }
        $document = $this->entityManager->find(ReceivedDocument::class, $id);
        if (!$document instanceof ReceivedDocument || ReceivedDocumentState::Recorded !== $document->state()) {
            return null;
        }
        $day = $document->taxPointDate() ?? $document->issueDate();
        if (!$day instanceof \DateTimeImmutable) {
            return null;
        }
        $supplier = $document->supplierParty();
        $name = $document->supplier()->name;

        return new CostDocument(
            $document->id(),
            $supplier?->id(),
            new \DateTimeImmutable($day->format('Y-m-d'), $this->clock->timezone()),
            array_map(static fn (LineView $line): CostLine => new CostLine($line->key, $line->text, $line->baseCzk), $document->effectiveLines()),
            $document->isTrashed(),
            trim(($document->evidenceNumber() ?? $document->supplierNumber()).' '.$name),
            $this->urls->generate('app_purchasing_document', ['id' => $document->id()], UrlGeneratorInterface::ABSOLUTE_PATH),
        );
    }

    public function documentIds(): iterable
    {
        /** @var list<array{id: string}> $rows */
        $rows = $this->entityManager->createQuery(
            'SELECT d.id AS id FROM '.ReceivedDocument::class.' d WHERE d.state = :state AND d.deletedAt IS NULL ORDER BY d.id ASC',
        )
            ->setParameter('state', ReceivedDocumentState::Recorded->value)
            ->getArrayResult();
        foreach ($rows as $row) {
            yield $row['id'];
        }
    }

    public function documentsOf(string $supplierId, \DateTimeImmutable $fromMonth): iterable
    {
        $from = $fromMonth->setTimezone($this->clock->timezone())->format('Y-m-01');
        /** @var list<array{id: string}> $rows */
        $rows = $this->entityManager->createQuery(
            'SELECT d.id AS id FROM '.ReceivedDocument::class.' d'
            .' WHERE d.state = :state AND d.deletedAt IS NULL AND d.supplierParty = :supplier AND COALESCE(d.taxPointDate, d.issueDate) >= :from'
            .' ORDER BY d.id ASC',
        )
            ->setParameter('state', ReceivedDocumentState::Recorded->value)
            ->setParameter('supplier', $supplierId)
            ->setParameter('from', $from)
            ->getArrayResult();
        foreach ($rows as $row) {
            yield $row['id'];
        }
    }
}
