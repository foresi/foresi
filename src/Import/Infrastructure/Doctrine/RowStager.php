<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Infrastructure\Doctrine;

use App\Import\Domain\Import;
use App\Import\Domain\RowOutcome;
use App\Shared\Domain\Id;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Stages rows with multi-row INSERT statements of one chunk each, straight
 * through the connection: a source of 200,000 rows never becomes 200,000
 * managed entities, and memory stays flat. The tenant id is written explicitly,
 * as the entity listener that stamps it does not see these rows.
 */
final readonly class RowStager
{
    public const int CHUNK_SIZE = 500;

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @param iterable<int, list<string>> $rows cells by 1-based source row number
     *
     * @return int the number of rows staged
     */
    public function stageRows(Import $import, iterable $rows): int
    {
        $importId = $import->id();
        $tenant = $import->tenantId()->toString();
        $batch = [];
        $count = 0;
        foreach ($rows as $rowNumber => $cells) {
            $batch[] = [$tenant, $importId, $rowNumber, intdiv($count, self::CHUNK_SIZE), json_encode($cells, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE)];
            ++$count;
            if (self::CHUNK_SIZE === \count($batch)) {
                $this->insertRows($batch);
                $batch = [];
            }
        }
        if ([] !== $batch) {
            $this->insertRows($batch);
        }

        return $count;
    }

    /**
     * Stages the records a full replace would archive, in chunks numbered after the source rows.
     *
     * @param iterable<string> $recordIds
     *
     * @return int the number of records staged
     */
    public function stageMissing(Import $import, iterable $recordIds): int
    {
        $importId = $import->id();
        $tenant = $import->tenantId()->toString();
        $batch = [];
        $count = 0;
        foreach ($recordIds as $recordId) {
            $batch[] = [$tenant, $importId, $import->chunks() + intdiv($count, self::CHUNK_SIZE), $recordId];
            ++$count;
            if (self::CHUNK_SIZE === \count($batch)) {
                $this->insertMissing($batch);
                $batch = [];
            }
        }
        if ([] !== $batch) {
            $this->insertMissing($batch);
        }

        return $count;
    }

    /** An id column as the database driver returns it. */
    public static function id(mixed $value): string
    {
        return \is_string($value) ? $value : throw new \UnexpectedValueException('An id column holds a text.');
    }

    /**
     * @param list<array{string, string, int, int, string}> $batch
     */
    private function insertRows(array $batch): void
    {
        $params = [];
        $types = [];
        foreach ($batch as [$tenant, $importId, $rowNumber, $chunk, $raw]) {
            $params[] = Id::generate();
            $params[] = $tenant;
            $params[] = $importId;
            $params[] = $rowNumber;
            $params[] = $chunk;
            $params[] = $raw;
            $params[] = '[]';
            $params[] = false;
            $params[] = false;
            $types[] = ParameterType::STRING;
            $types[] = ParameterType::STRING;
            $types[] = ParameterType::STRING;
            $types[] = ParameterType::INTEGER;
            $types[] = ParameterType::INTEGER;
            $types[] = ParameterType::STRING;
            $types[] = ParameterType::STRING;
            $types[] = ParameterType::BOOLEAN;
            $types[] = ParameterType::BOOLEAN;
        }
        $this->connection->executeStatement(
            'INSERT INTO import_row (id, tenant_id, import_id, row_number, chunk, raw, errors, processed_in_run, processed_in_undo) VALUES '
            .implode(', ', array_fill(0, \count($batch), '(?, ?, ?, ?, ?, ?, ?, ?, ?)')),
            $params,
            $types,
        );
    }

    /**
     * @param list<array{string, string, int, string}> $batch
     */
    private function insertMissing(array $batch): void
    {
        $params = [];
        $types = [];
        foreach ($batch as [$tenant, $importId, $chunk, $recordId]) {
            $params[] = Id::generate();
            $params[] = $tenant;
            $params[] = $importId;
            $params[] = $chunk;
            $params[] = $recordId;
            $params[] = RowOutcome::Archive->value;
            $params[] = false;
            $params[] = false;
            $types[] = ParameterType::STRING;
            $types[] = ParameterType::STRING;
            $types[] = ParameterType::STRING;
            $types[] = ParameterType::INTEGER;
            $types[] = ParameterType::STRING;
            $types[] = ParameterType::STRING;
            $types[] = ParameterType::BOOLEAN;
            $types[] = ParameterType::BOOLEAN;
        }
        $this->connection->executeStatement(
            'INSERT INTO import_missing_record (id, tenant_id, import_id, chunk, record_id, outcome, processed_in_run, processed_in_undo) VALUES '
            .implode(', ', array_fill(0, \count($batch), '(?, ?, ?, ?, ?, ?, ?, ?)')),
            $params,
            $types,
        );
    }
}
