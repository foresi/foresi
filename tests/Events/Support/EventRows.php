<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Events\Support;

use App\Events\Infrastructure\Dbal\EventLogTable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

/**
 * Writes and reads rows of the event log directly, for the tests that need a row
 * the recorder would not write as it is: a chosen id, an old date or a position.
 */
final class EventRows
{
    public static function insert(Connection $db, string $id, string $tenant, string $occurredAt = '2026-11-12 10:00:00', ?int $position = null, string $name = 'invoicing.invoice.issued'): void
    {
        $db->insert(EventLogTable::LOG, [
            EventLogTable::ID => $id,
            EventLogTable::TENANT_ID => $tenant,
            EventLogTable::NAME => $name,
            EventLogTable::VERSION => 1,
            EventLogTable::OCCURRED_AT => new \DateTimeImmutable($occurredAt, new \DateTimeZone('UTC')),
            EventLogTable::SUBJECT_TYPE => 'invoice',
            EventLogTable::SUBJECT_ID => $id,
            EventLogTable::DATA => ['invoice_id' => $id, 'line_count' => 1, 'delivered' => false, 'due_on' => null, 'kind' => 'invoice', 'line_codes' => []],
            EventLogTable::CORRELATION_ID => null,
            EventLogTable::ACTOR_KIND => 'system',
            EventLogTable::POSITION => $position,
        ], [
            EventLogTable::OCCURRED_AT => Types::DATETIME_IMMUTABLE,
            EventLogTable::DATA => Types::JSON,
        ]);
    }

    /**
     * @return array<string, int|null> the position of each row, by id
     */
    public static function positions(Connection $db): array
    {
        $positions = [];
        foreach ($db->fetchAllAssociative('SELECT id, position FROM event_log ORDER BY id') as $row) {
            $id = $row['id'];
            $position = $row['position'];
            if (!\is_string($id) || (null !== $position && !is_numeric($position))) {
                throw new \UnexpectedValueException('The event log holds a column of an unexpected type.');
            }
            $positions[$id] = null === $position ? null : (int) $position;
        }

        return $positions;
    }
}
