<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Events\Support;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Events\Infrastructure\Dbal\EventLogTable;

/**
 * The events a test left in the log, in the order they were recorded. With the
 * tests of Events, which every application has, so the tests of any module can
 * use it.
 */
trait ReadsEventLog
{
    /**
     * @return list<array{subject: string, data: array<mixed>}> the events of one name
     */
    private function events(string $name): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $rows = $em->getConnection()->fetchAllAssociative(
            'SELECT '.EventLogTable::SUBJECT_ID.', '.EventLogTable::DATA.' FROM '.EventLogTable::LOG.' WHERE '.EventLogTable::NAME.' = ? ORDER BY '.EventLogTable::ID,
            [$name],
        );

        return array_map(static function (array $row): array {
            $data = json_decode(\is_string($row[EventLogTable::DATA]) ? $row[EventLogTable::DATA] : '', true, flags: \JSON_THROW_ON_ERROR);
            \assert(\is_array($data) && \is_string($row[EventLogTable::SUBJECT_ID]));

            return ['subject' => $row[EventLogTable::SUBJECT_ID], 'data' => $data];
        }, $rows);
    }

    private function eventCount(): int
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $count = $em->getConnection()->fetchOne('SELECT COUNT(*) FROM '.EventLogTable::LOG);
        \assert(is_numeric($count));

        return (int) $count;
    }
}
