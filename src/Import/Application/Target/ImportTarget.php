<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Application\Target;

use App\Import\Domain\BlankCells;
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

    /**
     * Puts the record back as the snapshot describes it. A record the import
     * created is archived; its before-state is RecordSnapshot::none(), or what
     * the target noted to undo along with it.
     */
    public function revert(int $id, RecordSnapshot $before): void;

    /**
     * Whether the record, or anything that belongs to it, changed at or after
     * $since outside the given audit correlation. Undo leaves such a record as it is.
     *
     * @param RecordSnapshot $before the snapshot the write returned, which may name what it added
     */
    public function changedSince(int $id, RecordSnapshot $before, \DateTimeImmutable $since, string $exceptCorrelationId): bool;

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
}
