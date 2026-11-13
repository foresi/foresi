<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Infrastructure\Dbal;

use App\Tenancy\Application\TenantContext;
use App\Webhooks\Application\Query\AttemptRow;
use App\Webhooks\Application\Query\DeliveryRecords;
use App\Webhooks\Application\Query\DeliveryRow;
use App\Webhooks\Infrastructure\Dbal\WebhookDeliveryTable as T;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;

/**
 * The delivery log read from its tables, always within the active
 * organisation: the tables hold no entity, so the tenant filter does not see
 * them and every query names the tenant itself. The body is never read.
 */
final readonly class DbalDeliveryRecords implements DeliveryRecords
{
    private const array DELIVERY_COLUMNS = [T::ID, T::ENDPOINT_ID, T::EVENT_ID, T::EVENT_TYPE, T::KIND, T::STATUS, T::ATTEMPTS, T::LAST_STATUS_CODE, T::NEXT_ATTEMPT_AT, T::CREATED_AT, T::FINISHED_AT];

    public function __construct(private Connection $connection, private TenantContext $context)
    {
    }

    public function before(string $endpointId, ?string $beforeId, int $limit): array
    {
        $query = $this->deliveries($endpointId)
            ->orderBy(T::ID, 'DESC')
            ->setMaxResults($limit);
        if (null !== $beforeId) {
            $query->andWhere(T::ID.' < :before')->setParameter('before', $beforeId);
        }

        return array_values(array_map($this->delivery(...), $query->fetchAllAssociative()));
    }

    public function find(string $endpointId, string $deliveryId): ?DeliveryRow
    {
        $row = $this->deliveries($endpointId)
            ->andWhere(T::ID.' = :id')
            ->setParameter('id', $deliveryId)
            ->fetchAssociative();

        return false === $row ? null : $this->delivery($row);
    }

    public function attempts(string $deliveryId): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select(T::ID, T::ATTEMPTED_AT, T::DURATION_MS, T::STATUS_CODE, T::ERROR, T::TRIGGER)
            ->from(T::ATTEMPT)
            ->where(T::DELIVERY_ID.' = :delivery')
            ->andWhere(T::TENANT_ID.' = :tenant')
            ->orderBy(T::ATTEMPTED_AT)
            ->addOrderBy(T::ID)
            ->setParameter('delivery', $deliveryId)
            ->setParameter('tenant', $this->tenant())
            ->fetchAllAssociative();

        return array_values(array_map(fn (array $row): AttemptRow => new AttemptRow(
            $this->text($row[T::ID]),
            $this->moment($row[T::ATTEMPTED_AT]) ?? throw new \UnexpectedValueException('A webhook attempt has no time.'),
            $this->number($row[T::DURATION_MS]) ?? 0,
            $this->number($row[T::STATUS_CODE]),
            null === $row[T::ERROR] ? null : $this->text($row[T::ERROR]),
            $this->text($row[T::TRIGGER]),
        ), $rows));
    }

    private function deliveries(string $endpointId): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select(...self::DELIVERY_COLUMNS)
            ->from(T::DELIVERY)
            ->where(T::ENDPOINT_ID.' = :endpoint')
            ->andWhere(T::TENANT_ID.' = :tenant')
            ->setParameter('endpoint', $endpointId)
            ->setParameter('tenant', $this->tenant());
    }

    /**
     * @param array<string, mixed> $row
     */
    private function delivery(array $row): DeliveryRow
    {
        return new DeliveryRow(
            $this->text($row[T::ID]),
            $this->text($row[T::ENDPOINT_ID]),
            $this->text($row[T::EVENT_ID]),
            $this->text($row[T::EVENT_TYPE]),
            $this->text($row[T::KIND]),
            $this->text($row[T::STATUS]),
            $this->number($row[T::ATTEMPTS]) ?? 0,
            $this->number($row[T::LAST_STATUS_CODE]),
            $this->moment($row[T::NEXT_ATTEMPT_AT]),
            $this->moment($row[T::CREATED_AT]) ?? throw new \UnexpectedValueException('A webhook delivery has no creation time.'),
            $this->moment($row[T::FINISHED_AT]),
        );
    }

    private function tenant(): string
    {
        return $this->context->current()->toString();
    }

    private function text(mixed $value): string
    {
        return \is_string($value) ? $value : throw new \UnexpectedValueException('A webhook delivery holds a column of an unexpected type.');
    }

    private function number(mixed $value): ?int
    {
        return null === $value ? null : (is_numeric($value) ? (int) $value : throw new \UnexpectedValueException('A webhook delivery holds a column of an unexpected type.'));
    }

    private function moment(mixed $value): ?\DateTimeImmutable
    {
        $moment = Type::getType(Types::DATETIME_IMMUTABLE)->convertToPHPValue($value, $this->connection->getDatabasePlatform());

        return null === $moment || $moment instanceof \DateTimeImmutable ? $moment : throw new \UnexpectedValueException('A webhook delivery holds a column of an unexpected type.');
    }
}
