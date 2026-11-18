<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Import\Contract;

/**
 * Exports records of an import target through the target's own field list, so
 * the file can be imported again. The module that owns the records offers the
 * export on its list.
 */
interface RecordExport
{
    /**
     * @param iterable<string> $ids the records, in the order of the file
     */
    public function write(string $targetKey, iterable $ids, SourceFormat $format, string $path): void;
}
