<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Infrastructure\Messenger;

use App\Audit\Application\AuditCorrelation;
use App\Import\Application\ImportCompletion;
use App\Import\Application\ImportGate;
use App\Import\Application\ImportJobs;
use App\Import\Application\Imports;
use App\Import\Application\Message\ArchiveMissing;
use App\Import\Application\Message\RunImportChunk;
use App\Import\Application\RowReader;
use App\Import\Application\Target\ImportTarget;
use App\Import\Application\Target\TargetRegistry;
use App\Import\Application\Target\WriteResult;
use App\Import\Domain\Import;
use App\Import\Domain\ImportMode;
use App\Import\Domain\ImportRow;
use App\Import\Domain\ImportState;
use App\Import\Domain\RowError;
use App\Import\Domain\RowOutcome;
use App\Import\Domain\ValueNormaliser;
use App\Import\Infrastructure\Doctrine\RowSavepoint;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Writes one chunk into the target, in one transaction and under the import's
 * audit correlation. Every row is read and matched again (the data may have
 * changed since the check) and written in its own savepoint, so a row the
 * target rejects becomes an error and the chunk goes on. Rows already written
 * are skipped and counted once, so a redelivered chunk changes nothing.
 */
#[AsMessageHandler]
final readonly class RunImportChunkHandler
{
    public function __construct(
        private Imports $imports,
        private TargetRegistry $targets,
        private ValueNormaliser $normaliser,
        private RowSavepoint $savepoint,
        private AuditCorrelation $correlation,
        private ImportCompletion $completion,
        private ImportGate $gate,
        private ImportJobs $jobs,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RunImportChunk $message): void
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
        if (ImportState::Running !== $import->state()) {
            return;
        }
        $target = $this->targets->get($import->targetKey());
        $reader = new RowReader($target, $import, $this->normaliser);

        $next = $this->imports->transactional(function () use ($message, $import, $target, $reader): string {
            // Rows an undo has already passed stay as they are: a chunk still in flight when the run stopped must not write behind the undo.
            $ids = array_map(
                static fn (ImportRow $row): string => $row->id(),
                array_values(array_filter($this->imports->lockRowsOfChunk($import, $message->chunk), static fn (ImportRow $row): bool => !$row->processedInRun() && !$row->processedInUndo())),
            );
            $counts = $this->correlation->within(
                'import:'.$message->importId,
                fn (): array => array_count_values(array_map(fn (string $id): string => $this->runRow($id, $import, $target, $reader)->value, $ids)),
            );

            $import = $this->imports->findLocked($message->importId) ?? throw new \LogicException('The import is gone.');
            if ([] !== $counts) {
                $import->recordChunk($counts);
                if (ImportState::Running === $import->state() && $import->reachedErrorThreshold()) {
                    $import->moveTo(ImportState::Stopped, \DateTimeImmutable::createFromInterface($this->clock->now()));

                    return 'released';
                }
            }
            if (ImportState::Running !== $import->state() || $import->chunksDone() < $import->chunks()) {
                return 'none';
            }
            if (ImportMode::FullReplace === $import->options()->mode && $this->imports->hasMissingChunk($import, $import->chunks())) {
                return 'archive';
            }
            $this->completion->finish($import);

            return 'released';
        });

        match ($next) {
            'archive' => $this->jobs->dispatch(new ArchiveMissing($message->importId, $import->chunks())),
            'released' => $this->gate->release($import->targetKey()),
            default => null,
        };
    }

    private function runRow(string $id, Import $import, ImportTarget $target, RowReader $reader): RowOutcome
    {
        $outcome = RowOutcome::Error;
        $failure = $this->savepoint->attempt(function () use ($id, $import, $target, $reader, &$outcome): void {
            $row = $this->imports->findRow($id) ?? throw new \LogicException('The row is gone.');
            $outcome = $this->write($row, $import, $target, $reader);
            $row->markProcessed();
        });
        if (!$failure instanceof \Throwable) {
            return $outcome;
        }

        // Never the cell values in the log: only where it happened and what kind of failure it was.
        $this->logger->warning('Import {import} could not write row {row}: {class}', ['import' => $import->id(), 'row' => $id, 'class' => $failure::class]);
        $marked = $this->savepoint->attempt(function () use ($id): void {
            $row = $this->imports->findRow($id) ?? throw new \LogicException('The row is gone.');
            $row->decide(RowOutcome::Error, [new RowError('', 'import.error.write_failed')]);
            $row->record(null, null);
            $row->markProcessed();
        });
        // Not even the error could be stored: the chunk fails as a whole and is retried.
        if ($marked instanceof \Throwable) {
            throw $marked;
        }

        return RowOutcome::Error;
    }

    private function write(ImportRow $row, Import $import, ImportTarget $target, RowReader $reader): RowOutcome
    {
        // A key the check found repeated in the file stays an error: the first row with it wins.
        if (array_any($row->errors(), static fn (RowError $error): bool => 'import.error.duplicate_in_file' === $error->messageKey)) {
            $row->decide(RowOutcome::Error, $row->errors());
            $row->record(null, null);

            return RowOutcome::Error;
        }
        $evaluation = $reader->read($row);
        $matches = [] === $evaluation->errors ? $reader->matches($evaluation) : [];
        [$outcome, $errors] = $reader->decide($evaluation, $matches);
        if (\count($matches) > 1) {
            $this->imports->addAmbiguousMatches($import, array_map(static fn ($match): string => $match->id, $matches));
        }
        $blankCells = $import->options()->blankCells;
        $result = match ($outcome) {
            RowOutcome::Create => $target->create($evaluation->values),
            RowOutcome::Update => $target->update($matches[0]->id, $evaluation->values, $blankCells),
            RowOutcome::Restore => $this->restore($target, $matches[0]->id, $evaluation->values, $import),
            default => null,
        };
        $row->decide($outcome, $errors);
        if ($result instanceof WriteResult) {
            $row->record($result->id, $result->before->data);
        } else {
            $row->record(1 === \count($matches) ? $matches[0]->id : null, null);
        }

        return $outcome;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function restore(ImportTarget $target, string $id, array $values, Import $import): WriteResult
    {
        $target->restoreArchived($id);

        return $target->update($id, $values, $import->options()->blankCells);
    }
}
