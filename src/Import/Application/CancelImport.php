<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Application;

use App\Import\Domain\ImportState;
use Psr\Clock\ClockInterface;

/**
 * Cancels an import that has written nothing yet: one being read, mapped,
 * checked or waiting in the queue. A running import cannot be cancelled, so it
 * never ends half written without an undo; it can be undone once it ends.
 */
final readonly class CancelImport
{
    public function __construct(
        private Imports $imports,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws ImportRefused
     */
    public function cancel(int $importId): void
    {
        $this->imports->transactional(function () use ($importId): void {
            $import = $this->imports->findLocked($importId) ?? throw ImportRefused::notFound();
            if (ImportState::Running === $import->state() || !$import->state()->canMoveTo(ImportState::Cancelled)) {
                throw new ImportRefused('import.refused.not_cancellable');
            }
            $import->moveTo(ImportState::Cancelled, \DateTimeImmutable::createFromInterface($this->clock->now()));
        });
    }
}
