<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Application;

use App\Import\Domain\Import;
use App\Import\Domain\ImportRow;

interface Imports
{
    /** Stores the import and writes pending changes. */
    public function add(Import $import): void;

    /** Writes the changes of imports and rows loaded before. */
    public function save(): void;

    public function find(int $id): ?Import;

    /**
     * Loads the import with a row lock (SELECT ... FOR UPDATE on PostgreSQL) and
     * fresh values, even when it is already loaded. Call it inside a transaction, then Import::recordChunk(), then save().
     */
    public function findLocked(int $id): ?Import;

    /**
     * Stages rows and writes them. The caller stages a chunk at a time and clears
     * the persistence session between chunks, or memory grows with the source.
     *
     * @param list<ImportRow> $rows
     */
    public function addRows(array $rows): void;

    /**
     * @return list<ImportRow> ordered by row number
     */
    public function rowsOfChunk(Import $import, int $chunk): array;
}
