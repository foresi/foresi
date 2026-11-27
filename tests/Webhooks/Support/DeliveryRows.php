<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Webhooks\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Foresi\Shared\Domain\Id;
use Foresi\Webhooks\Infrastructure\Dbal\WebhookDeliveryTable as T;

/**
 * Writes and reads delivery rows as the fan-out and the handler leave them.
 */
final readonly class DeliveryRows
{
    public const string BODY = '{"id":"evt","type":"contacts.contact.created","data":{"note":"body-marker"}}';

    public function __construct(private Connection $db)
    {
    }

    /**
     * @return string the id of the delivery
     */
    public function insert(
        string $endpointId,
        string $status = T::STATUS_PENDING,
        int $attempts = 0,
        ?string $nextAttemptAt = '2026-11-13 10:00:00',
        string $kind = T::KIND_EVENT,
        string $createdAt = '2026-11-13 10:00:00',
    ): string {
        $id = Id::generate();
        $this->db->insert(T::DELIVERY, [
            T::ID => $id,
            T::ENDPOINT_ID => $endpointId,
            T::EVENT_ID => Id::generate(),
            T::EVENT_TYPE => 'contacts.contact.created',
            T::BODY => self::BODY,
            T::STATUS => $status,
            T::ATTEMPTS => $attempts,
            T::NEXT_ATTEMPT_AT => null === $nextAttemptAt ? null : new \DateTimeImmutable($nextAttemptAt),
            T::KIND => $kind,
            T::CREATED_AT => new \DateTimeImmutable($createdAt),
            T::FINISHED_AT => T::STATUS_PENDING === $status ? null : new \DateTimeImmutable($createdAt),
        ], [
            T::ATTEMPTS => Types::SMALLINT,
            T::NEXT_ATTEMPT_AT => Types::DATETIME_IMMUTABLE,
            T::CREATED_AT => Types::DATETIME_IMMUTABLE,
            T::FINISHED_AT => Types::DATETIME_IMMUTABLE,
        ]);

        return $id;
    }

    public function attempt(string $deliveryId, string $at): string
    {
        $id = Id::generate();
        $this->db->insert(T::ATTEMPT, [
            T::ID => $id,
            T::DELIVERY_ID => $deliveryId,
            T::ATTEMPTED_AT => new \DateTimeImmutable($at),
            T::DURATION_MS => 12,
            T::STATUS_CODE => 500,
            T::TRIGGER => 'schedule',
        ], [T::ATTEMPTED_AT => Types::DATETIME_IMMUTABLE]);

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    public function delivery(string $id): array
    {
        $row = $this->db->fetchAssociative(\sprintf('SELECT * FROM %s WHERE %s = ?', T::DELIVERY, T::ID), [$id]);
        \assert(\is_array($row));

        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function attempts(string $deliveryId): array
    {
        return $this->db->fetchAllAssociative(\sprintf('SELECT * FROM %s WHERE %s = ? ORDER BY %s', T::ATTEMPT, T::DELIVERY_ID, T::ATTEMPTED_AT), [$deliveryId]);
    }
}
