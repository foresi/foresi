<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application\Reading;

use Foresi\Audit\Application\AuditCorrelation;
use Foresi\Purchasing\Application\PurchasingAccess;
use Foresi\Purchasing\Application\PurchasingNotAllowed;
use Foresi\Purchasing\Application\PurchasingUnit;
use Foresi\Purchasing\Application\ReceivedDocuments;
use Foresi\Purchasing\Application\UnknownReceivedDocument;
use Foresi\Purchasing\Domain\DocumentReading;
use Foresi\Purchasing\Domain\ReadingState;
use Foresi\Purchasing\Domain\ReceivedDocumentRefused;
use Psr\Clock\ClockInterface;

/**
 * A person asks for the file of a draft to be read again: after a failure,
 * after nothing was found, or to run the readers that only run on request.
 * The reading may fill the draft as it is now (its base version is the
 * draft's version). On a draft nobody touched since its last reading, what
 * it finds is taken as on arrival; on a touched one it never replaces a value
 * a person typed: it fills what is empty and suggests the rest.
 *
 * A reading that is still running is not asked for twice, unless it was asked
 * for so long ago that it is stuck (a job lost on the way to the queue).
 */
final readonly class ReadAgain
{
    /** After this long, a reading that never finished counts as stuck and may be asked for again. */
    public const string STUCK_AFTER = '10 minutes';

    public function __construct(
        private PurchasingAccess $access,
        private PurchasingUnit $unit,
        private ReceivedDocuments $documents,
        private DocumentReadings $readings,
        private ReadingQueue $queue,
        private AuditCorrelation $correlation,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PurchasingNotAllowed    for anyone but the owners and members of the organisation
     * @throws UnknownReceivedDocument
     * @throws ReceivedDocumentRefused with RECORDED, TRASHED, NO_ORIGINAL_FILE, or READING_IN_PROGRESS while it runs
     */
    public function readAgain(string $documentId): void
    {
        $this->access->denyUnlessStaff();
        $message = $this->unit->run(function () use ($documentId): ReadReceivedDocument {
            // The document first, then its reading (DocumentReadings).
            $document = $this->documents->lock($documentId);
            if ($document->isTrashed()) {
                throw new ReceivedDocumentRefused(ReceivedDocumentRefused::TRASHED);
            }
            if ($document->isRecorded()) {
                throw new ReceivedDocumentRefused(ReceivedDocumentRefused::RECORDED);
            }
            if (null === $document->originalAttachmentId()) {
                throw new ReceivedDocumentRefused(ReceivedDocumentRefused::NO_ORIGINAL_FILE);
            }
            $now = \DateTimeImmutable::createFromInterface($this->clock->now());
            $reading = $this->readings->find($document->id());
            if ($reading instanceof DocumentReading && !self::mayStart($reading, $now)) {
                throw new ReceivedDocumentRefused(ReceivedDocumentRefused::READING_IN_PROGRESS);
            }
            $keepTyped = $reading instanceof DocumentReading && $this->touchedSince($reading, $document->version());
            if ($reading instanceof DocumentReading) {
                $reading->start($document->version(), $now);
            } else {
                // A draft uploaded before drafts were read.
                $this->readings->add(new DocumentReading($document, $document->version(), $now));
            }

            return new ReadReceivedDocument($document->id(), $document->version(), true, $this->correlation->current(), $keepTyped);
        });
        $this->queue->queue($message);
    }

    /**
     * Whether a person changed the draft since its last reading: it has a
     * version that neither the reading was asked for nor the reading's own
     * save made. Such a draft keeps what a person typed.
     */
    private function touchedSince(DocumentReading $reading, int $version): bool
    {
        $base = $reading->baseVersion();
        // A reading that took values saved the draft once.
        $own = ReadingState::Read === $reading->state() && [] !== $reading->applied();

        return $version !== $base && (!$own || $version !== $base + 1);
    }

    /**
     * Whether a person may ask for this reading again now: it finished, or it is stuck.
     */
    public static function mayStart(DocumentReading $reading, \DateTimeImmutable $now): bool
    {
        return !$reading->isRunning() || $reading->requestedAt() <= $now->modify('-'.self::STUCK_AFTER);
    }
}
