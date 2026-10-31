<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Application;

use App\Import\Application\Message\CheckImportChunk;
use App\Import\Application\Target\TargetRegistry;
use App\Import\Domain\ImportState;

/**
 * Starts the dry run: every staged row is normalised, validated and matched in
 * the background, nothing is written to the target. The result counts what a
 * run would do.
 */
final readonly class CheckImport
{
    public function __construct(
        private Imports $imports,
        private TargetRegistry $targets,
        private ImportJobs $jobs,
    ) {
    }

    /**
     * @throws ImportRefused
     */
    public function check(int $importId): void
    {
        $chunks = $this->imports->transactional(function () use ($importId): int {
            $import = $this->imports->findLocked($importId) ?? throw ImportRefused::notFound();
            if (ImportState::Mapping !== $import->state()) {
                throw new ImportRefused('import.refused.not_editable');
            }
            $missing = $import->mapping()->missingRequired($this->targets->get($import->targetKey())->fields());
            if ([] !== $missing) {
                throw new ImportRefused('import.refused.required_unmapped', ['fields' => implode(', ', $missing)]);
            }
            $import->moveTo(ImportState::Checking);
            $import->countRows($import->total(), $import->chunks());
            $import->findArchiveCandidates(0);
            $import->confirmArchive(null);
            $this->imports->resetCheck($import);

            return $import->chunks();
        });
        for ($chunk = 0; $chunk < $chunks; ++$chunk) {
            $this->jobs->dispatch(new CheckImportChunk($importId, $chunk));
        }
    }
}
