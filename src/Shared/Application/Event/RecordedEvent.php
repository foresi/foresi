<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Application\Event;

use App\Shared\Domain\Event\DomainEventPayload;

/**
 * A contract event as it was stored: its id, name and version, when it
 * happened, the correlation it belongs to and its facts. What a listener
 * receives.
 */
final readonly class RecordedEvent
{
    public function __construct(
        public string $id,
        public string $name,
        public int $version,
        public \DateTimeImmutable $occurredAt,
        public ?string $correlationId,
        public DomainEventPayload $payload,
    ) {
    }
}
