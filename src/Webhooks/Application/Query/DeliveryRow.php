<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Application\Query;

/**
 * One delivery of an event, or a ping, to an endpoint, as the log shows it: its
 * state and how many attempts it took, never the body it is sent with.
 */
final readonly class DeliveryRow
{
    public const string PENDING = 'pending';
    public const string SUCCEEDED = 'succeeded';
    public const string FAILED = 'failed';
    public const string EVENT = 'event';
    public const string PING = 'ping';

    public function __construct(
        public string $id,
        public string $endpointId,
        public string $eventId,
        public string $eventType,
        public string $kind,
        public string $status,
        public int $attempts,
        public ?int $lastStatusCode,
        public ?\DateTimeImmutable $nextAttemptAt,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $finishedAt,
    ) {
    }

    public function isPending(): bool
    {
        return self::PENDING === $this->status;
    }

    public function isPing(): bool
    {
        return self::PING === $this->kind;
    }
}
