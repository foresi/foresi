<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Integration\CashFlow;

use Foresi\CashFlow\Contract\FlowDirection;
use Foresi\CashFlow\Contract\FlowSubject;
use Foresi\CashFlow\Contract\PairableDocument;
use Foresi\CashFlow\Contract\PairableDocumentSource;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Purchasing\Application\Query\ReceivedDocumentQueries;
use Foresi\Purchasing\Application\Query\ReceivedDocumentSliceFilter;
use Foresi\Purchasing\Application\ReceivedDocuments;
use Foresi\Purchasing\Domain\ReceivedDocument;
use Foresi\Purchasing\Domain\ReceivedDocumentKind;
use Foresi\Purchasing\Domain\ReceivedDocumentState;
use Foresi\Purchasing\Infrastructure\Api\ReceivedDocumentApiController;
use Foresi\Shared\Domain\Money;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The recorded received documents for Cash flow to pair with planned occurrences. A draft and a document in the
 * trash are not offered. The counterparty is the supplier contact, the direction is Out (In for a credit note),
 * the amount is the total in the document's currency without its sign, and the day is the tax point, else the
 * issue date, else the day it was received, as a calendar day in the instance zone.
 */
final readonly class ReceivedDocumentPairables implements PairableDocumentSource
{
    public const string TYPE = 'received_document';
    private const int CHUNK = 200;

    public function __construct(
        private ReceivedDocuments $documents,
        private ReceivedDocumentQueries $queries,
        private InstanceClock $instanceClock,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function one(string $subjectType, string $subjectId): ?PairableDocument
    {
        return self::TYPE === $subjectType ? $this->pairable($this->documents->find($subjectId)) : null;
    }

    public function many(string $subjectType, array $subjectIds): iterable
    {
        if (self::TYPE !== $subjectType) {
            return;
        }
        foreach ($this->documents->findMany($subjectIds) as $document) {
            $pairable = $this->pairable($document);
            if ($pairable instanceof PairableDocument) {
                yield $pairable;
            }
        }
    }

    public function of(string $counterpartyId, \DateTimeImmutable $from, \DateTimeImmutable $to): iterable
    {
        $filter = new ReceivedDocumentSliceFilter(state: ReceivedDocumentState::Recorded, supplierId: $counterpartyId, dayFrom: $from, dayTo: $to);
        foreach ($this->recorded($filter) as $document) {
            $pairable = $this->pairable($document);
            if ($pairable instanceof PairableDocument) {
                yield $pairable;
            }
        }
    }

    public function since(\DateTimeImmutable $from): iterable
    {
        foreach ($this->recorded(new ReceivedDocumentSliceFilter(state: ReceivedDocumentState::Recorded, dayFrom: $from)) as $document) {
            $pairable = $this->pairable($document);
            if ($pairable instanceof PairableDocument) {
                yield $pairable;
            }
        }
    }

    /**
     * @return iterable<ReceivedDocument>
     */
    private function recorded(ReceivedDocumentSliceFilter $filter): iterable
    {
        $after = null;
        do {
            [$documents, $more] = $this->queries->slice($filter, $after, self::CHUNK);
            yield from $documents;
            $after = $more && [] !== $documents ? $documents[array_key_last($documents)]->id() : null;
        } while (null !== $after);
    }

    private function label(ReceivedDocument $document): string
    {
        return trim(\sprintf('%s %s', '' === $document->supplierNumber() ? ($document->evidenceNumber() ?? '') : $document->supplierNumber(), $document->supplier()->name));
    }

    private function pairable(?ReceivedDocument $document): ?PairableDocument
    {
        if (!$document instanceof ReceivedDocument || !$document->isRecorded() || $document->isTrashed()) {
            return null;
        }
        $total = $document->total();
        $day = $document->taxPointDate() ?? $document->issueDate() ?? $document->receivedDate();
        if (!$total instanceof Money || !$day instanceof \DateTimeImmutable) {
            return null;
        }

        return new PairableDocument(
            new FlowSubject(self::TYPE, $document->id(), $this->urls->generate(ReceivedDocumentApiController::SHOW, ['documentId' => $document->id()], UrlGeneratorInterface::ABSOLUTE_PATH), $this->label($document)),
            $document->supplierParty()?->id(),
            ReceivedDocumentKind::CreditNote === $document->kind() ? FlowDirection::In : FlowDirection::Out,
            $total->isNegative() ? Money::ofMinor(-$total->minor(), $total->currency()) : $total,
            new \DateTimeImmutable($day->format('Y-m-d'), $this->instanceClock->timezone()),
        );
    }
}
