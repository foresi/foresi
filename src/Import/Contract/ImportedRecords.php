<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Import\Contract;

/**
 * What one import did with the records of a target, for the module that owns
 * them: its list narrowed to "the records this import created", with a link
 * back to the import.
 */
interface ImportedRecords
{
    /** The name of the imported file; null when there is no such import. */
    public function fileName(string $importId): ?string;

    /** The page that sums the import up. */
    public function summaryUrl(string $importId): string;

    /**
     * A DQL condition that $recordId, an expression for the id of a record,
     * names a record the import gave $outcome, with its parameters. The
     * parameter names start with $prefix.
     *
     * @return array{string, array<string, string>}
     */
    public function condition(string $importId, RowOutcome $outcome, string $recordId, string $prefix = 'import'): array;
}
