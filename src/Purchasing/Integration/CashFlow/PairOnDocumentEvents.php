<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Integration\CashFlow;

use Foresi\CashFlow\Contract\DocumentPairer;
use Foresi\CashFlow\Contract\FlowSubject;
use Foresi\CashFlow\Contract\PairableDocument;
use Foresi\Purchasing\Contract\Event\ReceivedDocumentRecorded;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Lets Cash flow look at a received document when it is recorded: it pairs with a planned occurrence or is
 * proposed for one. A synchronous listener of Purchasing's own event (ADR 0030), so the pairing is committed
 * or rolled back with the recording.
 *
 * Seam for the events that follow a change of a recorded document, its trash and its restore: a listener of
 * each calls reconsider() with the document's id.
 */
final readonly class PairOnDocumentEvents
{
    public function __construct(private ReceivedDocumentPairables $documents, private DocumentPairer $pairer)
    {
    }

    #[AsEventListener]
    public function onRecorded(ReceivedDocumentRecorded $event): void
    {
        $this->reconsider($event->receivedDocumentId);
    }

    /**
     * Reads the document again: a recorded one is considered, one that is gone from the pairable documents
     * (trashed, or no longer complete) is forgotten.
     */
    public function reconsider(string $documentId): void
    {
        $document = $this->documents->one(ReceivedDocumentPairables::TYPE, $documentId);
        if ($document instanceof PairableDocument) {
            $this->pairer->consider($document);

            return;
        }
        $this->pairer->forget(new FlowSubject(ReceivedDocumentPairables::TYPE, $documentId));
    }
}
