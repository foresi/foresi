<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Integration\Import;

use Foresi\Import\Contract\ImportedRecords;
use Foresi\Import\Contract\RowOutcome;
use Foresi\Party\Application\ContactImports;
use Foresi\Party\Application\ImportedBy;

/**
 * The imports of the address book, as the Import module keeps them.
 */
final readonly class ImportedContacts implements ContactImports
{
    public function __construct(private ImportedRecords $records)
    {
    }

    public function fileName(string $importId): ?string
    {
        return $this->records->fileName($importId);
    }

    public function summaryUrl(string $importId): string
    {
        return $this->records->summaryUrl($importId);
    }

    public function condition(ImportedBy $import, string $partyId): array
    {
        return $this->records->condition($import->importId, RowOutcome::from($import->outcome), $partyId);
    }
}
