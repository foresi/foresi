<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Infrastructure\Doctrine;

use App\Import\Application\Imports;
use App\Import\Application\RowReader;
use App\Import\Domain\Import;
use App\Import\Domain\ImportRow;
use App\Import\Domain\RowOutcome;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Finds the rows whose match key an earlier row of the same file already used:
 * the first row wins, the later ones become errors. Runs once all chunks are
 * checked, as chunks are checked in parallel and in any order.
 */
final readonly class DuplicateRows
{
    private const int BATCH = 500;

    public function __construct(
        private Connection $connection,
        private Imports $imports,
        private EntityManagerInterface $em,
    ) {
    }

    public function mark(Import $import, RowReader $reader): void
    {
        $ids = array_map(RowStager::id(...), $this->connection->fetchFirstColumn(
            'SELECT r.id FROM import_row r WHERE r.import_id = ? AND r.match_value IS NOT NULL'
            .' AND EXISTS (SELECT 1 FROM import_row e WHERE e.import_id = r.import_id AND e.match_value = r.match_value AND e.row_number < r.row_number)'
            .' ORDER BY r.row_number',
            [$import->id()],
            [ParameterType::STRING],
        ));
        foreach (array_chunk($ids, self::BATCH) as $batch) {
            $rows = array_filter(array_map($this->imports->findRow(...), $batch), static fn (?ImportRow $row): bool => $row instanceof ImportRow);
            foreach ($rows as $row) {
                $errors = RowOutcome::Error === $row->outcome() ? $row->errors() : [];
                $row->decide(RowOutcome::Error, [...$errors, $reader->duplicateError($row)]);
            }
            $this->em->flush();
            // Let the batch go, so memory stays flat when a whole file repeats one key.
            foreach ($rows as $row) {
                $this->em->detach($row);
            }
        }
    }
}
