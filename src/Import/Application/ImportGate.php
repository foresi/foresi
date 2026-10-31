<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Application;

use App\Import\Application\Message\RunImportChunk;
use App\Import\Domain\Import;
use App\Import\Domain\ImportState;
use App\Tenancy\Application\TenantContext;
use Psr\Clock\ClockInterface;

/**
 * Lets one import per tenant and target run or undo at a time; the others wait
 * in Queued. The database state decides (an import in Running or Undoing holds
 * the target); a named lock around each decision keeps two requests or workers
 * from deciding at once. When an import stops holding the target, release()
 * starts the one that has waited longest.
 */
final readonly class ImportGate
{
    public function __construct(
        private Imports $imports,
        private ImportLock $lock,
        private ImportJobs $jobs,
        private TenantContext $tenants,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Runs a decision about the target inside the gate and in one transaction, then
     * sends the messages it returned once the decision is stored.
     *
     * @param callable(): list<object> $decide
     */
    public function decide(string $targetKey, callable $decide): void
    {
        $messages = $this->lock->synchronized(
            \sprintf('import-gate:%s:%s', $this->tenants->current()->toString(), $targetKey),
            fn (): array => $this->imports->transactional($decide),
        );
        foreach ($messages as $message) {
            $this->jobs->dispatch($message);
        }
    }

    /** Call inside decide(): whether another import holds the target. */
    public function isBusy(string $targetKey, int $exceptImportId): bool
    {
        return $this->imports->isBusy($targetKey, $exceptImportId);
    }

    /**
     * Call inside decide() for a Queued import of a free target: starts the run.
     *
     * @return list<object> the chunks to send once the start is stored
     */
    public function start(Import $import): array
    {
        if ($import->chunks() < 1) {
            throw new \LogicException('An import without rows is never run.');
        }
        $import->countRows($import->total(), $import->chunks());
        $import->moveTo(ImportState::Running, \DateTimeImmutable::createFromInterface($this->clock->now()));
        $id = $import->id() ?? throw new \LogicException('A queued import is stored.');

        return array_map(static fn (int $chunk): RunImportChunk => new RunImportChunk($id, $chunk), range(0, $import->chunks() - 1));
    }

    /** Call once an import stopped holding the target (finished, stopped, failed, undone). */
    public function release(string $targetKey): void
    {
        $this->decide($targetKey, function () use ($targetKey): array {
            if ($this->imports->isBusy($targetKey, 0)) {
                return [];
            }
            $next = $this->imports->oldestQueued($targetKey);
            $next = $next instanceof Import ? $this->imports->findLocked((int) $next->id()) : null;

            return $next instanceof Import && ImportState::Queued === $next->state() ? $this->start($next) : [];
        });
    }
}
