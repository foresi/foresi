<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Application;

use App\Import\Application\Message\UndoImportChunk;
use App\Import\Domain\ImportState;
use Psr\Clock\ClockInterface;

/**
 * Undoes a finished, stopped or failed import that wrote records, in the background: created records are
 * archived, updated ones get their previous values back, archived ones are
 * restored. A record someone changed after the import is left alone and listed.
 */
final readonly class UndoImport
{
    public function __construct(
        private Imports $imports,
        private ImportGate $gate,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws ImportRefused when the window has passed, the staged rows are purged, or another import holds the target
     */
    public function undo(int $importId): void
    {
        $import = $this->imports->find($importId) ?? throw ImportRefused::notFound();
        $targetKey = $import->targetKey();

        $this->gate->decide($targetKey, function () use ($importId, $targetKey): array {
            $import = $this->imports->findLocked($importId) ?? throw ImportRefused::notFound();
            $refusal = $import->undoRefusal(\DateTimeImmutable::createFromInterface($this->clock->now()));
            if (null !== $refusal) {
                throw new ImportRefused($refusal);
            }
            if ($this->gate->isBusy($targetKey, $importId)) {
                throw new ImportRefused('import.refused.target_busy');
            }
            $import->moveTo(ImportState::Undoing);

            return [new UndoImportChunk($importId, 0)];
        });
    }
}
