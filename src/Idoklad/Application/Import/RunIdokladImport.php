<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Idoklad\Application\Import;

use Foresi\Idoklad\Application\IdokladSnapshot;
use Foresi\Idoklad\Domain\IdokladImportRun;
use Foresi\Idoklad\Domain\IdokladPull;
use Foresi\Idoklad\Domain\IdokladResource;
use Foresi\Idoklad\Domain\ImportMode;
use Foresi\Idoklad\Domain\ImportOptions;
use Foresi\Idoklad\Domain\ImportReport;
use Foresi\Shared\Domain\Id;
use Psr\Clock\ClockInterface;

/**
 * Imports the snapshot of the iDoklad account: every stage in its order,
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
        private ClockInterface $clock,
    ) {
        $ordered = [...$stages];
        usort($ordered, static fn (ImportStage $a, ImportStage $b): int => $a->order() <=> $b->order());
        $this->stages = $ordered;
    }

    /**
     * @param ImportOptions|null $options what the person chose; null for the defaults of the snapshot
     */
    public function run(ImportMode $mode, ?ImportOptions $options = null): IdokladImportRun
    {
        $runId = Id::generate();
        $startedAt = $this->now();
        $report = new ImportReport();
        $pull = $this->snapshot->lastSucceededPull();
        $error = $pull instanceof IdokladPull ? null : self::NO_SNAPSHOT;
        $options ??= $this->defaultOptions();
        if (null === $error) {
            $context = new ImportContext($runId, $mode, $report, $this->snapshot, $this->links, $startedAt, $options);
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

        return $this->transaction->run(function () use ($mode, $startedAt, $runId, $pull, $report, $error, $options): IdokladImportRun {
            $run = new IdokladImportRun($mode, $startedAt, $runId);
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
    public function defaultOptions(): ImportOptions
    {
        $earliest = null;
        foreach ($this->snapshot->records(IdokladResource::IssuedInvoices) as $record) {
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
