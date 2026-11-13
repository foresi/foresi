<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Infrastructure\Delivery;

use App\Tenancy\Application\Schedule\OperatorTask;
use App\Webhooks\Infrastructure\Dbal\WebhookDeliveryTable as T;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Psr\Clock\ClockInterface;

/**
 * Deletes finished deliveries made more than thirty days ago, with their
 * attempts, once a day, as the event log keeps its events. A delivery still
 * waiting for an attempt is never deleted, however old.
 */
final readonly class PruneWebhookDeliveriesTask implements OperatorTask
{
    public const string NAME = 'webhook-delivery-prune';
    public const int KEEP_DAYS = 30;

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
        return '1 day';
    }

    public function run(): void
    {
        $before = $this->clock->now()->modify(\sprintf('-%d days', self::KEEP_DAYS));
        $this->connection->transactional(static function (Connection $connection) use ($before): void {
            $expired = \sprintf('%s <> :pending AND %s < :before', T::STATUS, T::CREATED_AT);
            $parameters = ['pending' => T::STATUS_PENDING, 'before' => $before];
            $types = ['before' => Types::DATETIME_IMMUTABLE];
            $connection->executeStatement(
                \sprintf('DELETE FROM %s WHERE %s IN (SELECT %s FROM %s WHERE %s)', T::ATTEMPT, T::DELIVERY_ID, T::ID, T::DELIVERY, $expired),
                $parameters,
                $types,
            );
            $connection->executeStatement(\sprintf('DELETE FROM %s WHERE %s', T::DELIVERY, $expired), $parameters, $types);
        });
    }
}
