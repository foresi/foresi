<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Infrastructure\Doctrine;

use App\Import\Application\Target\ImportTarget;
use App\Import\Domain\Import;
use App\Import\Domain\ImportProfile;
use App\Import\Domain\ImportState;
use App\Import\Domain\ReplaceScope;
use App\Import\Domain\RowOutcome;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Finds the records a full replace archives: the active records of its scope
 * that no row of the source matched, alone or among other records. Scope Profile reaches only records that
 * earlier finished imports of the same profile wrote and did not undo, so
 * records made by hand are never archived by it.
 */
final readonly class MissingRecordFinder
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return \Generator<int, string> record ids
     */
    public function find(Import $import, ImportTarget $target): \Generator
    {
        // Records a row matched alone, or among others: an ambiguous row still names them.
        $inSource = array_fill_keys($this->ids(
            'SELECT record_id FROM import_row WHERE import_id = ? AND record_id IS NOT NULL'
            .' UNION SELECT record_id FROM import_ambiguous_match WHERE import_id = ?',
            [$import->id(), $import->id()],
            [ParameterType::STRING, ParameterType::STRING],
        ), true);
        $scope = null;
        if (ReplaceScope::Profile === $import->options()->scope) {
            $profile = $import->profile();
            $scope = $profile instanceof ImportProfile ? array_fill_keys($this->writtenBy($import, $profile), true) : [];
        }

        foreach ($target->activeIds() as $id) {
            if (!isset($inSource[$id]) && (null === $scope || isset($scope[$id]))) {
                yield $id;
            }
        }
    }

    /**
     * @return list<string>
     */
    private function writtenBy(Import $import, ImportProfile $profile): array
    {
        return $this->ids(
            'SELECT DISTINCT r.record_id FROM import_row r INNER JOIN import i ON i.id = r.import_id'
            .' WHERE i.tenant_id = ? AND i.profile_id = ? AND i.target_key = ? AND i.id <> ? AND i.state IN (?)'
            .' AND r.processed_in_run = ? AND r.processed_in_undo = ? AND r.outcome IN (?) AND r.record_id IS NOT NULL',
            [
                $import->tenantId()->toString(), $profile->id(), $import->targetKey(), $import->id(),
                [ImportState::Finished->value, ImportState::FinishedWithErrors->value],
                true, false,
                [RowOutcome::Create->value, RowOutcome::Update->value, RowOutcome::Restore->value],
            ],
            [
                ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING,
                ArrayParameterType::STRING, ParameterType::BOOLEAN, ParameterType::BOOLEAN, ArrayParameterType::STRING,
            ],
        );
    }

    /**
     * @param list<mixed>                            $params
     * @param list<ParameterType|ArrayParameterType> $types
     *
     * @return list<string>
     */
    private function ids(string $sql, array $params, array $types): array
    {
        return array_map(RowStager::id(...), $this->connection->fetchFirstColumn($sql, $params, $types));
    }
}
