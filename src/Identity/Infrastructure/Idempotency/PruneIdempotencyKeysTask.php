<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Idempotency;

use App\Identity\Infrastructure\Idempotency\IdempotencyKeyTable as T;
use App\Instance\Application\Schedule\OperatorTask;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Psr\Clock\ClockInterface;

/**
 * Deletes the idempotency keys older than a day, once an hour, in every
 * organisation at once: a key is only ever compared within its own.
 */
final readonly class PruneIdempotencyKeysTask implements OperatorTask
{
    public const string NAME = 'api-idempotency-prune';

    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function every(): string
    {
        return '1 hour';
    }

    public function run(): void
    {
        $this->connection->executeStatement(
            \sprintf('DELETE FROM %s WHERE %s < :before', T::TABLE, T::CREATED_AT),
            ['before' => \DateTimeImmutable::createFromInterface($this->clock->now())->modify(\sprintf('-%d hours', DbalIdempotencyKeys::KEEP_HOURS))],
            ['before' => Types::DATETIME_IMMUTABLE],
        );
    }
}
