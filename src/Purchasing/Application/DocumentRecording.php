<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Application;

use App\Party\Application\ContactSettingsForFirm;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;
use App\Party\Domain\PartyRole;
use App\Purchasing\Contract\Event\ReceivedDocumentRecorded;
use App\Purchasing\Domain\EvidenceSeries;
use App\Purchasing\Domain\ReceivedDocument;
use App\Purchasing\Domain\ReceivedDocumentContent;
use App\Purchasing\Domain\ReceivedDocumentRefused;
use App\Shared\Application\Event\EventRecorder;
use App\Shared\Domain\Money;
use Psr\Clock\ClockInterface;

/**
 * Records a document, or saves a recorded one again, inside the unit of work
 * that locked it and its firm. A document recorded for the first time takes
 * the next evidence number of the firm's series, made with the standard format
 * when the firm has none yet; the number counts in the year of the received
 * date. Its supplier becomes a supplier of the firm. Recording a draft records
 * purchasing.received_document.recorded; saving a recorded document again does not.
 */
final readonly class DocumentRecording
{
    public function __construct(
        private EvidenceSeriesCatalog $series,
        private EvidenceNumbers $numbers,
        private ContactSettingsForFirm $contacts,
        private ClockInterface $clock,
        private EventRecorder $events,
    ) {
    }

    /**
     * @throws ReceivedDocumentRefused
     */
    public function record(ReceivedDocument $document, LegalEntity $firm, ReceivedDocumentContent $content, string $userId): void
    {
        // Checked first, so a number is taken only for a document that can be recorded.
        $document->checkRecordable($content);
        if (null === $document->evidenceNumber() && $content->receivedDate instanceof \DateTimeImmutable) {
            $document->assignEvidenceNumber($this->nextNumber($firm, $content->receivedDate));
        }
        $first = !$document->isRecorded();
        $document->record($content, $userId, \DateTimeImmutable::createFromInterface($this->clock->now()));
        if ($content->supplierParty instanceof Party) {
            $this->contacts->addRole($content->supplierParty, $firm, PartyRole::Supplier);
        }
        if ($first) {
            // A recorded document always has its total and its supplier.
            $total = $document->total();
            $supplier = $document->supplierParty();
            \assert($total instanceof Money && $supplier instanceof Party);
            $this->events->record(new ReceivedDocumentRecorded(
                $document->id(),
                $firm->id(),
                $document->kind()->value,
                $supplier->id(),
                EventAmount::of($total),
                $total->currency()->value,
                $document->dueDate()?->format('Y-m-d'),
            ));
        }
    }

    /**
     * @throws ReceivedDocumentRefused with EVIDENCE_NUMBERS_EXHAUSTED when the series has none left this year
     */
    private function nextNumber(LegalEntity $firm, \DateTimeImmutable $received): string
    {
        $series = $this->series->ofFirm($firm);
        if (!$series instanceof EvidenceSeries) {
            $series = EvidenceSeries::standard($firm);
            $this->series->add($series);
        }
        try {
            return $this->numbers->next($series, $received);
        } catch (EvidenceNumbersExhausted) {
            throw new ReceivedDocumentRefused(ReceivedDocumentRefused::EVIDENCE_NUMBERS_EXHAUSTED);
        }
    }
}
