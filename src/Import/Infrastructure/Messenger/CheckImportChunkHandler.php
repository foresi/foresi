<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Infrastructure\Messenger;

use App\Import\Application\Imports;
use App\Import\Application\Message\CheckImportChunk;
use App\Import\Application\RowReader;
use App\Import\Application\Target\TargetRegistry;
use App\Import\Domain\Import;
use App\Import\Domain\ImportMode;
use App\Import\Domain\ImportState;
use App\Import\Domain\ValueNormaliser;
use App\Import\Infrastructure\Doctrine\DuplicateRows;
use App\Import\Infrastructure\Doctrine\MissingRecordFinder;
use App\Import\Infrastructure\Doctrine\RowStager;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Checks one chunk: every row is read, validated and matched, and its outcome
 * stored; the target is not written. The chunk that completes the check finds
 * the keys repeated in the file, stages the records a full replace would archive
 * and counts the outcomes of the whole source.
 */
#[AsMessageHandler]
final readonly class CheckImportChunkHandler
{
    public function __construct(
        private Imports $imports,
        private TargetRegistry $targets,
        private ValueNormaliser $normaliser,
        private DuplicateRows $duplicates,
        private MissingRecordFinder $missing,
        private RowStager $stager,
    ) {
    }

    public function __invoke(CheckImportChunk $message): void
    {
        $import = $this->imports->findFresh($message->importId);
        if (!$import instanceof Import || ImportState::Checking !== $import->state()) {
            return;
        }
        $target = $this->targets->get($import->targetKey());

        $complete = $this->imports->transactional(function () use ($message, $import, $target): bool {
            $rows = $this->imports->lockRowsOfChunk($import, $message->chunk);
            // A chunk is checked in one transaction, so a redelivered one is either all done or not at all.
            $done = [] !== $rows && array_all($rows, static fn ($row): bool => null !== $row->outcome());
            $counts = [];
            $unidentified = 0;
            if (!$done) {
                $reader = new RowReader($target, $import, $this->normaliser);
                foreach ($rows as $row) {
                    $evaluation = $reader->read($row);
                    // A row with errors is matched too: a record the file holds is never archived as missing.
                    $matches = $reader->matches($evaluation);
                    [$outcome, $errors] = $reader->decide($evaluation, $matches);
                    $row->decide($outcome, $errors);
                    $row->keyBy($evaluation->matchValue);
                    $row->record(1 === \count($matches) ? $matches[0]->id : null, null);
                    if (\count($matches) > 1) {
                        $this->imports->addAmbiguousMatches($import, array_map(static fn ($match): string => $match->id, $matches));
                    }
                    $unidentified += $evaluation->keyUnreadable ? 1 : 0;
                    $counts[$outcome->value] = ($counts[$outcome->value] ?? 0) + 1;
                }
            }
            $import = $this->imports->findLocked($message->importId) ?? throw new \LogicException('The import is gone.');
            if (!$done && ImportState::Checking === $import->state()) {
                $import->recordChunk($counts);
                $import->recordUnidentified($unidentified);
            }

            return ImportState::Checking === $import->state() && $import->chunksDone() >= $import->chunks();
        });

        if ($complete) {
            $this->complete($message->importId);
        }
    }

    private function complete(string $importId): void
    {
        $this->imports->transactional(function () use ($importId): void {
            $import = $this->imports->findLocked($importId);
            if (!$import instanceof Import || ImportState::Checking !== $import->state() || $import->chunksDone() < $import->chunks()) {
                return;
            }
            $target = $this->targets->get($import->targetKey());
            $this->duplicates->mark($import, new RowReader($target, $import, $this->normaliser));
            $candidates = ImportMode::FullReplace === $import->options()->mode ? $this->stager->stageMissing($import, $this->missing->find($import, $target)) : 0;

            $import->recount($this->imports->outcomeCounts($import));
            $import->findArchiveCandidates($candidates);
            $import->moveTo(ImportState::Checked);
        });
    }
}
