<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Idoklad\Application\Import;

use App\Idoklad\Application\IdokladSnapshot;
use App\Idoklad\Domain\IdokladImportRun;
use App\Idoklad\Domain\IdokladPull;
use App\Idoklad\Domain\ImportMode;
use App\Idoklad\Domain\ImportReport;
use App\Party\Application\LegalEntities;
use App\Party\Domain\LegalEntity;
use App\Shared\Domain\Id;
use Psr\Clock\ClockInterface;

/**
 * Imports the snapshot of a firm's iDoklad account: every stage in its order,
 * in one transaction, so a run that stops stores nothing of what it did. A
 * check rolls the transaction back. Either way the run is stored afterwards
 * with its report; a failure is its error.
 */
final readonly class RunIdokladImport
{
    public const string NO_SNAPSHOT = 'The iDoklad account has no finished pull to import from.';

    /** @var list<ImportStage> */
    private array $stages;

    /**
     * @param iterable<ImportStage> $stages
     */
    public function __construct(
        iterable $stages,
        private IdokladSnapshot $snapshot,
        private IdokladLinks $links,
        private IdokladImportRuns $runs,
        private ImportTransaction $transaction,
        private LegalEntities $firms,
        private ClockInterface $clock,
    ) {
        $ordered = [...$stages];
        usort($ordered, static fn (ImportStage $a, ImportStage $b): int => $a->order() <=> $b->order());
        $this->stages = $ordered;
    }

    public function run(LegalEntity $firm, ImportMode $mode): IdokladImportRun
    {
        $runId = Id::generate();
        $startedAt = $this->now();
        $report = new ImportReport();
        $pull = $this->snapshot->lastSucceededPull($firm);
        $error = $pull instanceof IdokladPull ? null : self::NO_SNAPSHOT;
        if (null === $error) {
            $context = new ImportContext($firm, $runId, $mode, $report, $this->snapshot, $this->links, $startedAt);
            try {
                $this->transaction->run(function () use ($context): void {
                    foreach ($this->stages as $stage) {
                        $stage->run($context);
                    }
                }, $mode->writes());
            } catch (\Throwable $failure) {
                $error = $failure->getMessage();
            }
        }

        // A rollback detached the firm; it is read again.
        $firmId = $firm->id();

        return $this->transaction->run(function () use ($firmId, $mode, $startedAt, $runId, $pull, $report, $error): IdokladImportRun {
            $run = new IdokladImportRun($this->firms->get($firmId), $mode, $startedAt, $runId);
            if ($pull instanceof IdokladPull) {
                $run->readFrom($pull);
            }
            $run->finish($this->now(), $report, $error);
            $this->runs->add($run);

            return $run;
        }, true);
    }

    /**
     * @return list<string> the stages, in the order they run
     */
    public function stages(): array
    {
        return array_map(static fn (ImportStage $stage): string => $stage->key(), $this->stages);
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
