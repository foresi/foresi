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
use App\Import\Application\Target\TargetRegistry;
use App\Import\Domain\Import;
use App\Import\Domain\ImportMissingRecord;
use App\Import\Domain\ImportState;
use App\Import\Infrastructure\Doctrine\RowSavepoint;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Archives one chunk of the records a full replace did not find in the source,
 * then hands on to the next chunk; the last one ends the run. Only the records
 * the check staged (and the user confirmed) are archived, minus any that a row
 * of the run matched after all.
 */
#[AsMessageHandler]
final readonly class ArchiveMissingHandler
{
    public function __construct(
        private Imports $imports,
        private TargetRegistry $targets,
        private RowSavepoint $savepoint,
        private AuditCorrelation $correlation,
        private ImportCompletion $completion,
        private ImportGate $gate,
        private ImportJobs $jobs,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ArchiveMissing $message): void
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

        $next = $this->imports->transactional(function () use ($message, $import, $target): string {
            $records = array_values(array_filter($this->imports->lockMissingOfChunk($import, $message->chunk), static fn (ImportMissingRecord $record): bool => !$record->processedInRun()));
            $inSource = $this->imports->recordsInSource($import, array_map(static fn (ImportMissingRecord $record): int => $record->recordId(), $records));
            $ids = array_map(static fn (ImportMissingRecord $record): int => (int) $record->id(), $records);

            [$archived, $failed] = $this->correlation->within('import:'.$message->importId, function () use ($ids, $inSource, $target, $import): array {
                $archived = 0;
                $failed = 0;
                foreach ($ids as $id) {
                    $failure = $this->savepoint->attempt(function () use ($id, $inSource, $target, &$archived): void {
                        $record = $this->imports->findMissingRecord($id) ?? throw new \LogicException('The record is gone.');
                        if (\in_array($record->recordId(), $inSource, true)) {
                            $record->kept();

                            return;
                        }
                        $record->archived($target->archive($record->recordId())->data);
                        ++$archived;
                    });
                    if ($failure instanceof \Throwable) {
                        $this->logger->warning('Import {import} could not archive record {record}: {class}', ['import' => $import->id(), 'record' => $id, 'class' => $failure::class]);
                        // It stays active and counts as failed, so the run ends with errors and the summary lists it.
                        $marked = $this->savepoint->attempt(function () use ($id): void {
                            ($this->imports->findMissingRecord($id) ?? throw new \LogicException('The record is gone.'))->failed();
                        });
                        if ($marked instanceof \Throwable) {
                            throw $marked;
                        }
                        ++$failed;
                    }
                }

                return [$archived, $failed];
            });

            $import = $this->imports->findLocked($message->importId) ?? throw new \LogicException('The import is gone.');
            $import->recordArchived($archived, $failed);
            if (ImportState::Running !== $import->state()) {
                return 'none';
            }
            if ($this->imports->hasMissingChunk($import, $message->chunk + 1)) {
                return 'next';
            }
            $this->completion->finish($import);

            return 'released';
        });

        match ($next) {
            'next' => $this->jobs->dispatch(new ArchiveMissing($message->importId, $message->chunk + 1)),
            'released' => $this->gate->release($import->targetKey()),
            default => null,
        };
    }
}
