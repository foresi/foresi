<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Contacts\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Columns that another module adds to the import and the export of contacts,
 * for values it keeps for each contact. The address book offers them with its
 * own fields, checks a row with them, writes them once the contact is saved
 * and puts them back when the import is undone.
 */
#[AutoconfigureTag(self::TAG)]
interface ContactImportColumns
{
    public const string TAG = 'foresi.party.contact_import_columns';

    /**
     * A name of its own, under which an import keeps what an undo puts back.
     */
    public function key(): string;

    /**
     * @return list<ContactImportColumn>
     */
    public function columns(): array;

    /**
     * The rules a row's values break, by column key: a message key and its parameters.
     *
     * @param array<string, mixed> $values the normalised values of the row by field key
     *
     * @return array<string, array{string, array<string, int|string>}>
     */
    public function check(array $values): array;

    /**
     * Writes a row's values to the contact. A column the row has no value in
     * stays as it is, unless $clear and its cell is empty (a null value), which
     * gives it its default; $created says the contact is new.
     *
     * @param array<string, mixed> $values the normalised values of the row by field key
     */
    public function write(string $contactId, array $values, bool $clear, bool $created): void;

    /**
     * What an undo of an update puts back.
     *
     * @return array<string, mixed>
     */
    public function snapshot(string $contactId): array;

    /**
     * @param array<array-key, mixed> $snapshot what snapshot() gave
     */
    public function restore(string $contactId, array $snapshot): void;

    /**
     * @return array<string, string> the cells of the contact by column key
     */
    public function export(string $contactId): array;
}
