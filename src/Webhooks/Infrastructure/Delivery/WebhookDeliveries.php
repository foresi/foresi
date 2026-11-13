<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Infrastructure\Delivery;

use App\Shared\Domain\Id;
use App\Webhooks\Application\AttemptResult;
use App\Webhooks\Application\AttemptTrigger;
use App\Webhooks\Infrastructure\Dbal\WebhookDeliveryTable as T;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;

/**
 * The delivery rows and their attempts: who may attempt a delivery, what an
 * attempt came to, and when the next one is due.
 *
 * A scheduled attempt claims a due delivery by moving its next attempt two
 * minutes ahead, a lease: one changed row means this worker holds it, and a
 * worker that dies holding it leaves it due again for the sweep. A manual
 * redelivery or a ping claims by writing its attempt under the id its message
 * carries, so a second copy of the message collides with the first. Neither
 * needs a lock, and both read the same on SQLite and PostgreSQL.
 */
final readonly class WebhookDeliveries
{
    /** How long a claimed delivery is held before the sweep may queue it again. */
    public const string LEASE = '+120 seconds';

    public function __construct(private Connection $connection)
    {
    }

    /**
     * Claims a due pending delivery for a scheduled attempt.
     */
    public function claim(string $id, \DateTimeImmutable $now): ?ClaimedDelivery
    {
        $claimed = $this->connection->createQueryBuilder()
            ->update(T::DELIVERY)
            ->set(T::NEXT_ATTEMPT_AT, ':lease')
            ->where(T::ID.' = :id')
            ->andWhere(T::STATUS.' = :pending')
            ->andWhere(T::NEXT_ATTEMPT_AT.' <= :now')
            ->setParameter('lease', $now->modify(self::LEASE), Types::DATETIME_IMMUTABLE)
            ->setParameter('id', $id)
            ->setParameter('pending', T::STATUS_PENDING)
            ->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
            ->executeStatement();

        return 1 === $claimed ? $this->find($id) : null;
    }

    /**
     * Claims a delivery for a manual redelivery, of a finished event, or for a
     * ping, of a pending ping, by writing the attempt with the given id. The
     * attempt is filled in by record() once the request is made.
     */
    public function claimAttempt(string $id, string $attemptId, AttemptTrigger $trigger, \DateTimeImmutable $now): ?ClaimedDelivery
    {
        $delivery = $this->find($id);
        $claimable = match ($trigger) {
            AttemptTrigger::Ping => T::KIND_PING === $delivery?->kind && T::STATUS_PENDING === $delivery->status,
            AttemptTrigger::Manual => T::KIND_EVENT === $delivery?->kind && T::STATUS_PENDING !== $delivery->status,
            AttemptTrigger::Schedule => false,
        };
        if (!$delivery instanceof ClaimedDelivery || !$claimable) {
            return null;
        }

        try {
            $this->connection->insert(T::ATTEMPT, [
                T::ID => $attemptId,
                T::TENANT_ID => $delivery->tenantId,
                T::DELIVERY_ID => $delivery->id,
                T::ATTEMPTED_AT => $now,
                T::DURATION_MS => 0,
                T::TRIGGER => $trigger->value,
            ], [T::ATTEMPTED_AT => Types::DATETIME_IMMUTABLE, T::DURATION_MS => Types::INTEGER]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        return $delivery;
    }

    /**
     * Records what an attempt that started at $at came to: in the attempt its
     * claim wrote, or in a new one. Only the status, the time taken and a short
     * error are kept; never the body sent or the answer.
     */
    public function record(ClaimedDelivery $delivery, AttemptTrigger $trigger, ?string $attemptId, AttemptResult $result, \DateTimeImmutable $at): void
    {
        $values = [
            T::DURATION_MS => $result->durationMs,
            T::STATUS_CODE => $result->statusCode,
            T::ERROR => $result->error,
        ];
        $types = [T::DURATION_MS => Types::INTEGER, T::STATUS_CODE => Types::SMALLINT, T::ATTEMPTED_AT => Types::DATETIME_IMMUTABLE];
        if (null !== $attemptId) {
            $this->connection->update(T::ATTEMPT, $values, [T::ID => $attemptId], $types);
        } else {
            $this->connection->insert(T::ATTEMPT, [
                T::ID => Id::generate(),
                T::TENANT_ID => $delivery->tenantId,
                T::DELIVERY_ID => $delivery->id,
                T::ATTEMPTED_AT => $at,
                T::TRIGGER => $trigger->value,
                ...$values,
            ], $types);
        }

        $this->connection->createQueryBuilder()
            ->update(T::DELIVERY)
            ->set(T::ATTEMPTS, T::ATTEMPTS.' + 1')
            ->set(T::LAST_STATUS_CODE, ':status')
            ->where(T::ID.' = :id')
            ->setParameter('status', $result->statusCode, Types::SMALLINT)
            ->setParameter('id', $delivery->id)
            ->executeStatement();
    }

    public function schedule(string $id, \DateTimeImmutable $next): void
    {
        $this->connection->update(T::DELIVERY, [T::NEXT_ATTEMPT_AT => $next], [T::ID => $id], [T::NEXT_ATTEMPT_AT => Types::DATETIME_IMMUTABLE]);
    }

    /**
     * Ends a delivery: no attempt is due any more, unless a person redelivers it.
     */
    public function finish(string $id, string $status, \DateTimeImmutable $now): void
    {
        $this->connection->update(T::DELIVERY, [
            T::STATUS => $status,
            T::NEXT_ATTEMPT_AT => null,
            T::FINISHED_AT => $now,
        ], [T::ID => $id], [T::FINISHED_AT => Types::DATETIME_IMMUTABLE]);
    }

    /**
     * Stores the ping of an endpoint as a delivery that is never due on its own:
     * its one attempt is queued with SendWebhook(id, Ping, attempt id).
     *
     * @param string $pingId the webhook-id of the ping, unique per ping
     *
     * @return string the id of the delivery
     */
    public function addPing(string $tenantId, string $endpointId, string $pingId, string $body, \DateTimeImmutable $now): string
    {
        $id = Id::generate();
        $this->connection->insert(T::DELIVERY, [
            T::ID => $id,
            T::TENANT_ID => $tenantId,
            T::ENDPOINT_ID => $endpointId,
            T::EVENT_ID => $pingId,
            T::EVENT_TYPE => 'webhooks.endpoint.ping',
            T::BODY => $body,
            T::STATUS => T::STATUS_PENDING,
            T::ATTEMPTS => 0,
            T::NEXT_ATTEMPT_AT => null,
            T::KIND => T::KIND_PING,
            T::CREATED_AT => $now,
        ], [T::ATTEMPTS => Types::SMALLINT, T::CREATED_AT => Types::DATETIME_IMMUTABLE]);

        return $id;
    }

    /**
     * Pending deliveries due at $before or earlier, oldest first, with their tenant.
     *
     * @return list<array{id: string, tenant: string}>
     */
    public function dueBefore(\DateTimeImmutable $before, int $limit): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select(T::ID, T::TENANT_ID)
            ->from(T::DELIVERY)
            ->where(T::STATUS.' = :pending')
            ->andWhere(T::NEXT_ATTEMPT_AT.' <= :before')
            ->orderBy(T::NEXT_ATTEMPT_AT)
            ->addOrderBy(T::ID)
            ->setMaxResults($limit)
            ->setParameter('pending', T::STATUS_PENDING)
            ->setParameter('before', $before, Types::DATETIME_IMMUTABLE)
            ->fetchAllAssociative();

        $due = [];
        foreach ($rows as $row) {
            $due[] = ['id' => $this->text($row[T::ID]), 'tenant' => $this->text($row[T::TENANT_ID])];
        }

        return $due;
    }

    public function find(string $id): ?ClaimedDelivery
    {
        $row = $this->connection->createQueryBuilder()
            ->select(T::ID, T::TENANT_ID, T::ENDPOINT_ID, T::EVENT_ID, T::BODY, T::STATUS, T::ATTEMPTS, T::KIND)
            ->from(T::DELIVERY)
            ->where(T::ID.' = :id')
            ->setParameter('id', $id)
            ->fetchAssociative();
        if (false === $row) {
            return null;
        }

        return new ClaimedDelivery(
            $this->text($row[T::ID]),
            $this->text($row[T::TENANT_ID]),
            $this->text($row[T::ENDPOINT_ID]),
            $this->text($row[T::EVENT_ID]),
            $this->text($row[T::BODY]),
            $this->text($row[T::STATUS]),
            is_numeric($row[T::ATTEMPTS]) ? (int) $row[T::ATTEMPTS] : throw new \UnexpectedValueException('A webhook delivery holds a column of an unexpected type.'),
            $this->text($row[T::KIND]),
        );
    }

    private function text(mixed $value): string
    {
        return \is_string($value) ? $value : throw new \UnexpectedValueException('A webhook delivery holds a column of an unexpected type.');
    }
}
