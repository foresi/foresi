<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Infrastructure\Messenger;

use App\Audit\Application\AuditCorrelation;
use App\Import\Application\ImportGate;
use App\Import\Application\ImportJobs;
use App\Import\Application\Imports;
use App\Import\Application\Message\UndoImportChunk;
use App\Import\Application\Target\ImportTarget;
use App\Import\Application\Target\RecordSnapshot;
use App\Import\Application\Target\TargetRegistry;
use App\Import\Domain\Import;
use App\Import\Domain\ImportMissingRecord;
use App\Import\Domain\ImportRow;
use App\Import\Domain\ImportState;
use App\Import\Domain\RowOutcome;
use App\Import\Infrastructure\Doctrine\RowSavepoint;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Undoes one chunk, then hands on to the next; the source rows come first, then
 * the records a full replace archived. A record with an audit entry from after
 * the run started that the import did not write is left as it is and counted as
 * changed after the import. Rows already undone are skipped, so a redelivered
 * chunk changes nothing.
 */
#[AsMessageHandler]
final readonly class UndoImportChunkHandler
{
    public function __construct(
        private Imports $imports,
        private TargetRegistry $targets,
        private RowSavepoint $savepoint,
        private AuditCorrelation $correlation,
        private ImportGate $gate,
        private ImportJobs $jobs,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(UndoImportChunk $message): void
    {
        $import = $this->imports->findFresh($message->importId);
        if (!$import instanceof Import) {
            return;
        }
        // A message delivered again after the import ended: the release of the gate may not have happened (the worker died, or it threw), so do it now. It is idempotent.
        if ($import->state()->isEnd()) {
            $this->gate->release($import->targetKey());

            return;
        }
        if (ImportState::Undoing !== $import->state()) {
            return;
        }
        $target = $this->targets->get($import->targetKey());
        // Changes since the run started count, so an edit made while it ran is never overwritten either.
        $since = $import->startedAt() ?? throw new \LogicException('An undone import has run.');
        $sourceChunk = $message->chunk < $import->chunks();

        $next = $this->imports->transactional(function () use ($message, $import, $target, $since, $sourceChunk): string {
            $ids = $sourceChunk
                ? array_map(static fn (ImportRow $row): int => (int) $row->id(), array_values(array_filter($this->imports->lockRowsOfChunk($import, $message->chunk), static fn (ImportRow $row): bool => !$row->processedInUndo())))
                : array_map(static fn (ImportMissingRecord $record): int => (int) $record->id(), array_values(array_filter($this->imports->lockMissingOfChunk($import, $message->chunk), static fn (ImportMissingRecord $record): bool => !$record->processedInUndo())));

            $counts = $this->correlation->within('import-undo:'.$message->importId, function () use ($ids, $sourceChunk, $target, $since, $message): array {
                $counts = [RowOutcome::Reverted->value => 0, RowOutcome::ChangedAfterImport->value => 0];
                foreach ($ids as $id) {
                    $outcome = null;
                    $failure = $this->savepoint->attempt(function () use ($id, $sourceChunk, $target, $since, $message, &$outcome): void {
                        $outcome = $sourceChunk ? $this->undoRow($id, $target, $since, $message->importId) : $this->undoMissing($id, $target, $since, $message->importId);
                    });
                    // An undo that cannot put a record back must not end as done: the chunk is retried, then lands in the failure transport.
                    if ($failure instanceof \Throwable) {
                        throw $failure;
                    }
                    if (RowOutcome::Reverted === $outcome) {
                        ++$counts[RowOutcome::Reverted->value];
                    } elseif (RowOutcome::ChangedAfterImport === $outcome) {
                        ++$counts[RowOutcome::ChangedAfterImport->value];
                    }
                }

                return $counts;
            });

            $import = $this->imports->findLocked($message->importId) ?? throw new \LogicException('The import is gone.');
            $import->recordUndo($counts[RowOutcome::Reverted->value], $counts[RowOutcome::ChangedAfterImport->value]);
            if (ImportState::Undoing !== $import->state()) {
                return 'none';
            }
            if ($message->chunk + 1 < $import->chunks() || $this->imports->hasMissingChunk($import, $message->chunk + 1)) {
                return 'next';
            }
            $import->moveTo(ImportState::Undone, \DateTimeImmutable::createFromInterface($this->clock->now()));

            return 'released';
        });

        match ($next) {
            'next' => $this->jobs->dispatch(new UndoImportChunk($message->importId, $message->chunk + 1)),
            'released' => $this->gate->release($import->targetKey()),
            default => null,
        };
    }

    private function undoRow(int $id, ImportTarget $target, \DateTimeImmutable $since, int $importId): ?RowOutcome
    {
        $row = $this->imports->findRow($id) ?? throw new \LogicException('The row is gone.');
        $outcome = $row->outcome();
        $recordId = $row->recordId();
        $row->markUndone();
        if (!$row->processedInRun() || !$outcome instanceof RowOutcome || !$outcome->wroteRecord() || null === $recordId) {
            return null;
        }
        $before = new RecordSnapshot($row->before() ?? []);
        if ($target->changedSince($recordId, $before, $since, 'import:'.$importId)) {
            $row->decide(RowOutcome::ChangedAfterImport);

            return RowOutcome::ChangedAfterImport;
        }
        $target->revert($recordId, $before);
        // The run restored an archived record before updating it; the undo archives it again.
        if (RowOutcome::Restore === $outcome) {
            $target->archive($recordId);
        }
        $row->decide(RowOutcome::Reverted);

        return RowOutcome::Reverted;
    }

    private function undoMissing(int $id, ImportTarget $target, \DateTimeImmutable $since, int $importId): ?RowOutcome
    {
        $record = $this->imports->findMissingRecord($id) ?? throw new \LogicException('The record is gone.');
        if (!$record->processedInRun() || RowOutcome::Archive !== $record->outcome()) {
            $record->passedByUndo();

            return null;
        }
        if ($target->changedSince($record->recordId(), new RecordSnapshot($record->before() ?? []), $since, 'import:'.$importId)) {
            $record->undone(RowOutcome::ChangedAfterImport);

            return RowOutcome::ChangedAfterImport;
        }
        $target->restoreArchived($record->recordId());
        $record->undone(RowOutcome::Reverted);

        return RowOutcome::Reverted;
    }
}
