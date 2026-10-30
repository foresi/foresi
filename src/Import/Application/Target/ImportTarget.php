<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Application\Target;

use App\Import\Domain\FieldDefinition;
use App\Import\Domain\RowError;

/**
 * Something the import can fill with records. A module implements this to be
 * importable; the import knows nothing else about it.
 */
interface ImportTarget
{
    /** The stable key of the target, for example "party". */
    public function key(): string;

    /** The translation key of the target's name. */
    public function labelKey(): string;

    /**
     * @return list<FieldDefinition>
     */
    public function fields(): array;

    /**
     * @return list<string> field keys usable as match key, in default order
     */
    public function matchKeys(): array;

    /**
     * @param array<string, mixed> $values normalised by field key
     *
     * @return list<RowError>
     */
    public function validate(array $values): array;

    /**
     * @param array<string, mixed> $values
     *
     * @return list<RecordMatch>
     */
    public function match(string $matchKey, array $values): array;

    /**
     * @param array<string, mixed> $values
     */
    public function create(array $values): WriteResult;

    /**
     * @param array<string, mixed> $values
     *
     * @return WriteResult carries the snapshot from before the change
     */
    public function update(int $id, array $values, BlankCells $blankCells): WriteResult;

    public function restoreArchived(int $id): void;

    public function archive(int $id): RecordSnapshot;

    /** A record the import created has RecordSnapshot::none() as before-state and is archived. */
    public function revert(int $id, RecordSnapshot $before): void;

    /**
     * @return iterable<int> ids of all non-archived records
     */
    public function activeIds(): iterable;

    /**
     * @param list<int> $ids
     *
     * @return iterable<array<string, string>> export rows by field key
     */
    public function export(array $ids): iterable;

    /** The audited entity class, for the audit trail. */
    public function entityClass(): string;
}
