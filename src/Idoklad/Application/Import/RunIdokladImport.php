<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Idoklad\Application\Import;

use App\Idoklad\Application\IdokladSnapshot;
use App\Idoklad\Domain\IdokladImportRun;
use App\Idoklad\Domain\IdokladPull;
use App\Idoklad\Domain\IdokladResource;
use App\Idoklad\Domain\ImportMode;
use App\Idoklad\Domain\ImportOptions;
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

    /**
     * @param ImportOptions|null $options what the person chose; null for the defaults of the snapshot
     */
    public function run(LegalEntity $firm, ImportMode $mode, ?ImportOptions $options = null): IdokladImportRun
    {
        $runId = Id::generate();
        $startedAt = $this->now();
        $report = new ImportReport();
        $pull = $this->snapshot->lastSucceededPull($firm);
        $error = $pull instanceof IdokladPull ? null : self::NO_SNAPSHOT;
        $options ??= $this->defaultOptions($firm);
        if (null === $error) {
            $context = new ImportContext($firm, $runId, $mode, $report, $this->snapshot, $this->links, $startedAt, $options);
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

        return $this->transaction->run(function () use ($firmId, $mode, $startedAt, $runId, $pull, $report, $error, $options): IdokladImportRun {
            $run = new IdokladImportRun($this->firms->get($firmId), $mode, $startedAt, $runId);
            $run->ranWith($options);
            if ($pull instanceof IdokladPull) {
                $run->readFrom($pull);
            }
            $run->finish($this->now(), $report, $error);
            $this->runs->add($run);

            return $run;
        }, true);
    }

    /**
     * The options of the snapshot: the firm was a VAT payer from the tax point
     * (or the issue date) of its earliest issued invoice with a line of a rate
     * above zero, and never when it has none. iDoklad's VatRegime cannot say it:
     * it marks every document of an account as a tax document.
     */
    public function defaultOptions(LegalEntity $firm): ImportOptions
    {
        $earliest = null;
        foreach ($this->snapshot->records($firm, IdokladResource::IssuedInvoices) as $record) {
            $payload = $record->payload();
            $items = \is_array($payload['Items'] ?? null) ? array_filter($payload['Items'], \is_array(...)) : [];
            $day = $this->day($payload['DateOfTaxing'] ?? null) ?? $this->day($payload['DateOfIssue'] ?? null);
            if (null !== $day && (null === $earliest || $day < $earliest) && array_any($items, static fn (array $item): bool => 0 === ($item['ItemType'] ?? 0) && (\is_int($item['VatRate'] ?? null) || \is_float($item['VatRate'] ?? null)) && $item['VatRate'] > 0)) {
                $earliest = $day;
            }
        }

        return new ImportOptions(null === $earliest ? null : new \DateTimeImmutable($earliest));
    }

    /**
     * @return list<string> the stages, in the order they run
     */
    public function stages(): array
    {
        return array_map(static fn (ImportStage $stage): string => $stage->key(), $this->stages);
    }

    /** A calendar day of iDoklad as "Y-m-d"; null when it is none. */
    private function day(mixed $value): ?string
    {
        $day = \is_string($value) ? substr($value, 0, 10) : '';
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day);

        return false !== $date && $date->format('Y-m-d') === $day ? $day : null;
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
