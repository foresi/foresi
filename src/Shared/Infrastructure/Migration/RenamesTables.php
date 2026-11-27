<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Migration;

/**
 * A migration that renames tables with their rows. Doctrine's schema
 * comparator cannot say "renamed": a table renamed in the Schema of up() would
 * be dropped and created empty. Such a migration renames them in SQL of its
 * own and leaves the Schema alone, and says here what it renamed, so the
 * readers that play the migrations on an empty Schema (TableOwners,
 * MigrationRebase) follow the rename: the table then belongs to the module of
 * this migration under its new name.
 */
interface RenamesTables
{
    /**
     * @return array<non-empty-string, non-empty-string> the new name of each table it renames, by the old name
     */
    public function renamedTables(): array;
}
