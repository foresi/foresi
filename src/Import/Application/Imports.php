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
     * Loads the import with a row lock (SELECT ... FOR UPDATE on PostgreSQL). Call it
     * inside a transaction, then Import::recordChunk(), then save().
     */
    public function findLocked(int $id): ?Import;

    /**
     * Stages rows and writes them.
     *
     * @param list<ImportRow> $rows
     */
    public function addRows(array $rows): void;

    /**
     * @return list<ImportRow> ordered by row number
     */
    public function rowsOfChunk(Import $import, int $chunk): array;
}
