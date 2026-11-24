<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application\Reading;

use Foresi\Attachment\Application\AttachmentFiles;
use Foresi\Attachment\Application\AttachmentFileUnreadable;
use Foresi\Attachment\Application\Attachments;
use Foresi\Attachment\Domain\Attachment;
use Foresi\Audit\Application\AuditCorrelation;
use Foresi\Purchasing\Application\PurchasingUnit;
use Foresi\Purchasing\Application\ReceivedDocuments;
use Foresi\Purchasing\Application\UnknownReceivedDocument;
use Foresi\Purchasing\Contract\Reading\DocumentFile;
use Foresi\Purchasing\Contract\Reading\DocumentReader;
use Foresi\Purchasing\Contract\Reading\ReaderFailed;
use Foresi\Purchasing\Contract\Reading\Reading;
use Foresi\Purchasing\Domain\DocumentReading;
use Foresi\Purchasing\Domain\ReceivedDocument;
use Psr\Clock\ClockInterface;

/**
 * The background job that reads the original file of a received document. No
 * person runs it: it runs in the worker, within the correlation of the step
 * that asked for it, so the history shows the filled values with the upload.
 *
 * Every reader that supports the file runs, in reader order (a reader that
 * only runs on request only when a person asked); one that cannot read the
 * file is noted and the next one runs, and the merge decides between their
 * values. The file is read outside the transaction. The values are applied in
 * one unit with the document locked, its version checked again there, so a
 * person who saved the draft meanwhile keeps what they typed.
 *
 * A message for a reading that finished, or was asked for again at another
 * version, does nothing: running the job twice applies the reading once.
 */
final readonly class ReadDocument
{
    /**
     * @param iterable<DocumentReader> $readers in reader order
     */
    public function __construct(
        private iterable $readers,
        private ReadingMerge $merge,
        private ReceivedDocuments $documents,
        private DocumentReadings $readings,
        private ReadingApplication $application,
        private Attachments $attachments,
        private AttachmentFiles $files,
        private PurchasingUnit $unit,
        private AuditCorrelation $correlation,
        private ClockInterface $clock,
    ) {
    }

    public function read(ReadReceivedDocument $message): void
    {
        if (!$this->due($this->readings->find($message->documentId), $message)) {
            return;
        }
        $file = $this->original($message->documentId);
        if (!$file instanceof DocumentFile) {
            $this->settle($message, function (DocumentReading $reading): void {
                $reading->fail(DocumentReading::ORIGINAL_UNREADABLE, $this->now());
            });

            return;
        }
        $readings = [];
        $failed = false;
        foreach ($this->readers as $reader) {
            if (!$reader->supports($file) || (!$reader->automatic() && !$message->manual)) {
                continue;
            }
            try {
                $reading = $reader->read($file);
            } catch (ReaderFailed) {
                $failed = true;

                continue;
            }
            if ($reading instanceof Reading) {
                $readings[] = $reading;
            }
        }
        $merged = $this->merge->merge($readings);

        $this->settle($message, function (DocumentReading $reading, ReceivedDocument $document) use ($merged, $failed, $message): void {
            if ($merged->isEmpty() && $failed) {
                $reading->fail(DocumentReading::READER_FAILED, $this->now());

                return;
            }
            $applied = $this->application->apply($document, $merged, $message->baseVersion, $message->keepTyped);
            $reading->finish($merged, $applied->applied, $applied->suggested, $applied->warnings, $this->now());
        });
    }

    /**
     * When the queue gives up on the job, the reading says it failed, so the
     * draft shows it instead of a reading that never ends.
     */
    public function giveUp(ReadReceivedDocument $message): void
    {
        $this->settle($message, function (DocumentReading $reading): void {
            $reading->fail(DocumentReading::UNEXPECTED, $this->now());
        });
    }

    /**
     * Runs the work on the reading and its locked document in one unit, while
     * the reading still waits for this message.
     *
     * @param callable(DocumentReading, ReceivedDocument): void $work
     */
    private function settle(ReadReceivedDocument $message, callable $work): void
    {
        $unit = fn (): mixed => $this->unit->run(function () use ($message, $work): null {
            try {
                // The document first: whoever settles the reading waits for the one before.
                $document = $this->documents->lock($message->documentId);
            } catch (UnknownReceivedDocument) {
                return null;
            }
            // Read again by the lock, so a run that waited sees what the one before stored.
            $reading = $this->readings->find($message->documentId);
            if ($reading instanceof DocumentReading && $this->due($reading, $message)) {
                $work($reading, $document);
            }

            return null;
        });
        if (null === $message->correlationId || null !== $this->correlation->current()) {
            $unit();

            return;
        }
        $this->correlation->within($message->correlationId, $unit);
    }

    private function due(?DocumentReading $reading, ReadReceivedDocument $message): bool
    {
        return $reading instanceof DocumentReading && $reading->isRunning() && $reading->baseVersion() === $message->baseVersion;
    }

    /**
     * The original file of the document, or null when it has none or it cannot be read.
     */
    private function original(string $documentId): ?DocumentFile
    {
        $id = $this->documents->find($documentId)?->originalAttachmentId();
        $attachment = null === $id ? null : $this->attachments->get($id);
        if (!$attachment instanceof Attachment) {
            return null;
        }
        try {
            $bytes = file_get_contents($this->files->read($attachment->id(), $attachment->storageKey(), $attachment->sha256()));
        } catch (AttachmentFileUnreadable) {
            return null;
        }

        return false === $bytes ? null : new DocumentFile($attachment->fileName(), $attachment->mediaType(), $bytes);
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
