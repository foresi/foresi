<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Application;

use App\Import\Domain\ImportState;

/**
 * Runs a checked import: it starts at once when no other import of the tenant
 * holds the target, or waits in Queued. A full replace that would archive
 * records runs only with the number of those records typed as confirmation.
 */
final readonly class RunImport
{
    public function __construct(
        private Imports $imports,
        private ImportGate $gate,
    ) {
    }

    /**
     * @param int|null $archiveConfirmation the number of records to archive, as the user typed it
     *
     * @throws ImportRefused
     */
    public function run(int $importId, ?int $archiveConfirmation = null): void
    {
        $import = $this->imports->find($importId) ?? throw ImportRefused::notFound();
        $targetKey = $import->targetKey();

        $this->gate->decide($targetKey, function () use ($importId, $targetKey, $archiveConfirmation): array {
            $import = $this->imports->findLocked($importId) ?? throw ImportRefused::notFound();
            if (ImportState::Checked !== $import->state()) {
                throw new ImportRefused('import.refused.not_checked');
            }
            if ($import->needsArchiveConfirmation()) {
                $import->confirmArchive(null !== $archiveConfirmation && $archiveConfirmation > 0 ? $archiveConfirmation : null);
            }
            if (!$import->isArchiveConfirmed()) {
                throw new ImportRefused('import.refused.archive_not_confirmed', ['count' => $import->archiveCandidates()]);
            }
            $import->moveTo(ImportState::Queued);

            return $this->gate->isBusy($targetKey, $importId) ? [] : $this->gate->start($import);
        });
    }
}
