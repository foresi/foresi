<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Schedule;

use App\Tenancy\Application\Schedule\Heartbeats;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * One row per name, written by one upsert that is the same on SQLite and
 * PostgreSQL. Times are stored in UTC, to the second.
 */
final readonly class DoctrineHeartbeats implements Heartbeats
{
    private const string BEAT = <<<'SQL'
        INSERT INTO heartbeat (name, beat_at) VALUES (:name, :now)
        ON CONFLICT (name) DO UPDATE SET beat_at = excluded.beat_at
        SQL;

    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    public function beat(string $name): void
    {
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $this->connection->executeStatement(self::BEAT, ['name' => $name, 'now' => $now->format('Y-m-d H:i:s')]);
    }

    public function last(string $name): ?\DateTimeImmutable
    {
        $last = $this->connection->fetchOne('SELECT beat_at FROM heartbeat WHERE name = :name', ['name' => $name]);

        return \is_string($last) ? new \DateTimeImmutable($last, new \DateTimeZone('UTC')) : null;
    }
}
