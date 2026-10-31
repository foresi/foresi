<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Application;

use App\Import\Domain\Import;
use App\Import\Domain\ImportMissingRecord;
use App\Import\Domain\ImportRow;
use App\Import\Domain\RowOutcome;

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

    /**
     * Runs the work in one transaction and writes its changes. When it throws,
     * nothing is stored, and the persistence session is usable again (everything
     * loaded before is detached), so the caller can handle the error.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function transactional(callable $work): mixed;

    /** Loads the import with the values stored now, even when it is already loaded. */
    public function findFresh(int $id): ?Import;

    /**
     * The rows of a chunk with the values stored now, locked until the transaction
     * ends, so a chunk delivered twice at once is processed by one worker after the other.
     *
     * @return list<ImportRow> ordered by row number
     */
    public function lockRowsOfChunk(Import $import, int $chunk): array;

    /**
     * @return list<ImportMissingRecord> locked like lockRowsOfChunk()
     */
    public function lockMissingOfChunk(Import $import, int $chunk): array;

    /** Whether a chunk of missing records exists with this number. */
    public function hasMissingChunk(Import $import, int $chunk): bool;

    /** Forgets the result of an earlier check, so a new check starts clean. */
    public function resetCheck(Import $import): void;

    /** Removes the staged rows and missing records, so the file can be read again. */
    public function deleteRows(Import $import): void;

    /**
     * @return array<string, int> the number of rows per outcome, keyed by RowOutcome::value
     */
    public function outcomeCounts(Import $import): array;

    /** Whether another import of the active tenant runs or undoes into this target. */
    public function isBusy(string $targetKey, int $exceptImportId): bool;

    /** The import of the target that has waited longest in the queue. */
    public function oldestQueued(string $targetKey): ?Import;

    /**
     * The rows that ended with this outcome, in row order, streamed chunk by chunk.
     *
     * @return iterable<ImportRow>
     */
    public function rowsWithOutcome(Import $import, RowOutcome $outcome): iterable;

    public function findRow(int $id): ?ImportRow;

    /**
     * Remembers the records an ambiguous row matched, so they count as in the source.
     *
     * @param list<int> $recordIds
     */
    public function addAmbiguousMatches(Import $import, array $recordIds): void;

    public function findMissingRecord(int $id): ?ImportMissingRecord;

    /**
     * Which of the given records a source row of the import matched (alone or among others) or wrote.
     *
     * @param list<int> $recordIds
     *
     * @return list<int>
     */
    public function recordsInSource(Import $import, array $recordIds): array;
}
