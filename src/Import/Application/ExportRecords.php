<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Application;

use App\Import\Application\Source\SourceFormat;
use App\Import\Application\Target\ImportTarget;
use App\Import\Application\Target\TargetRegistry;

/**
 * Exports records of an import target through the target's own field list, in
 * batches so a large address book never sits in memory at once. The columns are
 * the fields the import maps to, which makes the file importable again.
 */
final readonly class ExportRecords
{
    public const int BATCH = 500;

    public function __construct(
        private TargetRegistry $targets,
        private ExportWriter $writer,
    ) {
    }

    /**
     * @param iterable<string> $ids
     */
    public function write(string $targetKey, iterable $ids, SourceFormat $format, string $path): void
    {
        $target = $this->targets->get($targetKey);
        $this->writer->write($target->fields(), $this->rows($target, $ids), $format, $path);
    }

    /**
     * @param iterable<string> $ids
     *
     * @return \Generator<mixed, array<string, string>>
     */
    private function rows(ImportTarget $target, iterable $ids): \Generator
    {
        $batch = [];
        foreach ($ids as $id) {
            $batch[] = $id;
            if (\count($batch) >= self::BATCH) {
                yield from $target->export($batch);
                $batch = [];
            }
        }
        if ([] !== $batch) {
            yield from $target->export($batch);
        }
    }
}
