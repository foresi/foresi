<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Infrastructure\Idempotency;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Foresi\Identity\Application\ApiTokenContext;
use Foresi\Identity\Application\ApiTokenGrant;
use Foresi\Identity\Infrastructure\Idempotency\IdempotencyKeyTable as T;
use Foresi\Shared\Application\Api\IdempotencyKeyInFlight;
use Foresi\Shared\Application\Api\IdempotencyKeyReused;
use Foresi\Shared\Application\Api\IdempotencyKeys;
use Foresi\Shared\Application\Api\SavedAnswer;
use Psr\Clock\ClockInterface;

/**
 * The idempotency keys in their table, by the organisation and the token of the
 * signed-in grant. Every statement runs on its own, outside the unit of work of
 * the write, so a claim holds while the write runs and a failed write does not
 * take its key with it. A claim left in flight by a request that died is taken
 * over after a minute; a key older than a day counts as gone, whether or not
 * the prune has deleted it yet.
 */
final readonly class DbalIdempotencyKeys implements IdempotencyKeys
{
    public const int KEEP_HOURS = 24;
    public const int IN_FLIGHT_SECONDS = 60;

    public function __construct(
        private Connection $connection,
        private ApiTokenContext $tokens,
        private ClockInterface $clock,
    ) {
    }

    public function claim(string $operation, string $key, string $fingerprint): ?SavedAnswer
    {
        $grant = $this->grant();
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        if ($this->insert($grant, $operation, $key, $fingerprint, $now)) {
            return null;
        }

        $row = $this->find($grant, $operation, $key);
        if (null === $row || $this->createdAt($row) < $now->modify(\sprintf('-%d hours', self::KEEP_HOURS))) {
            // Gone meanwhile, or so old it no longer counts: the key is new again.
            $this->release($operation, $key);
            if ($this->insert($grant, $operation, $key, $fingerprint, $now)) {
                return null;
            }
            throw new IdempotencyKeyInFlight();
        }
        if (!\is_string($row[T::FINGERPRINT]) || !hash_equals($row[T::FINGERPRINT], $fingerprint)) {
            throw new IdempotencyKeyReused();
        }
        if (T::STATUS_COMPLETED === $row[T::STATUS]) {
            return $this->answer($row);
        }
        if ($this->createdAt($row) < $now->modify(\sprintf('-%d seconds', self::IN_FLIGHT_SECONDS)) && $this->takeOver($grant, $operation, $key, $row, $now)) {
            return null;
        }

        throw new IdempotencyKeyInFlight();
    }

    public function complete(string $operation, string $key, SavedAnswer $answer): void
    {
        $grant = $this->grant();
        $this->connection->update(T::TABLE, [
            T::STATUS => T::STATUS_COMPLETED,
            T::RESPONSE_STATUS => $answer->status,
            T::RESPONSE_HEADERS => json_encode($answer->headers, \JSON_THROW_ON_ERROR),
            T::RESPONSE_BODY => $answer->body,
            T::COMPLETED_AT => \DateTimeImmutable::createFromInterface($this->clock->now()),
        ], $this->where($grant, $operation, $key), [T::COMPLETED_AT => Types::DATETIME_IMMUTABLE]);
    }

    public function release(string $operation, string $key): void
    {
        $this->connection->delete(T::TABLE, $this->where($this->grant(), $operation, $key));
    }

    private function insert(ApiTokenGrant $grant, string $operation, string $key, string $fingerprint, \DateTimeImmutable $now): bool
    {
        try {
            $this->connection->insert(T::TABLE, $this->where($grant, $operation, $key) + [
                T::FINGERPRINT => $fingerprint,
                T::STATUS => T::STATUS_IN_FLIGHT,
                T::CREATED_AT => $now,
            ], [T::CREATED_AT => Types::DATETIME_IMMUTABLE]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function answer(array $row): SavedAnswer
    {
        $status = $row[T::RESPONSE_STATUS];
        $body = $row[T::RESPONSE_BODY];
        $decoded = \is_string($row[T::RESPONSE_HEADERS]) ? json_decode($row[T::RESPONSE_HEADERS], true) : null;
        $headers = [];
        foreach (\is_array($decoded) ? $decoded : [] as $name => $value) {
            if (\is_string($name) && \is_string($value)) {
                $headers[$name] = $value;
            }
        }

        return new SavedAnswer(is_numeric($status) ? (int) $status : null, \is_string($body) ? $body : '', $headers);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function takeOver(ApiTokenGrant $grant, string $operation, string $key, array $row, \DateTimeImmutable $now): bool
    {
        // Only one of two requests that both find the stale claim moves its time on.
        return 1 === $this->connection->update(
            T::TABLE,
            [T::CREATED_AT => $now],
            $this->where($grant, $operation, $key) + [T::STATUS => T::STATUS_IN_FLIGHT, T::CREATED_AT => $this->createdAt($row)],
            [T::CREATED_AT => Types::DATETIME_IMMUTABLE],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function find(ApiTokenGrant $grant, string $operation, string $key): ?array
    {
        $row = $this->connection->fetchAssociative(
            \sprintf('SELECT %s, %s, %s, %s, %s, %s FROM %s WHERE %s = :token AND %s = :operation AND %s = :key', T::FINGERPRINT, T::STATUS, T::RESPONSE_STATUS, T::RESPONSE_HEADERS, T::RESPONSE_BODY, T::CREATED_AT, T::TABLE, T::TOKEN_ID, T::OPERATION, T::IDEMPOTENCY_KEY),
            ['token' => $grant->tokenId, 'operation' => $operation, 'key' => $key],
        );

        return false === $row ? null : $row;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function createdAt(array $row): \DateTimeImmutable
    {
        $value = $this->connection->convertToPHPValue($row[T::CREATED_AT], Types::DATETIME_IMMUTABLE);

        return $value instanceof \DateTimeImmutable ? $value : throw new \LogicException('An idempotency key without its time.');
    }

    /**
     * @return array<string, mixed>
     */
    private function where(ApiTokenGrant $grant, string $operation, string $key): array
    {
        return [T::TOKEN_ID => $grant->tokenId, T::OPERATION => $operation, T::IDEMPOTENCY_KEY => $key];
    }

    private function grant(): ApiTokenGrant
    {
        return $this->tokens->grant() ?? throw new \LogicException('Idempotency keys belong to a token; the request signed in without one.');
    }
}
