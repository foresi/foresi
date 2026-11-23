<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Integration\CashFlow;

use Foresi\CashFlow\Contract\DocumentPairer;
use Foresi\CashFlow\Contract\FlowSubject;
use Foresi\CashFlow\Contract\PairableDocument;
use Foresi\Purchasing\Contract\Event\ReceivedDocumentChanged;
use Foresi\Purchasing\Contract\Event\ReceivedDocumentRecorded;
use Foresi\Purchasing\Contract\Event\ReceivedDocumentRestored;
use Foresi\Purchasing\Contract\Event\ReceivedDocumentTrashed;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Lets Cash flow look at a received document when it is recorded, changed or restored: it pairs with a planned
 * occurrence or is proposed for one. When it is trashed Cash flow forgets it. Synchronous listeners of
 * Purchasing's own events (ADR 0030), so the pairing is committed or rolled back with the change.
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

    #[AsEventListener]
    public function onChanged(ReceivedDocumentChanged $event): void
    {
        $this->reconsider($event->receivedDocumentId);
    }

    #[AsEventListener]
    public function onTrashed(ReceivedDocumentTrashed $event): void
    {
        $this->reconsider($event->receivedDocumentId);
    }

    #[AsEventListener]
    public function onRestored(ReceivedDocumentRestored $event): void
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
