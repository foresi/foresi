<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Events\Application\Query;

/**
 * One positioned event of the log as the feed reads it. The tenant, the user and
 * the token behind it stay in the log and are not part of the row.
 */
final readonly class EventRow
{
    /**
     * @param array<string, mixed> $data the facts as they were stored
     */
    public function __construct(
        public string $id,
        public string $name,
        public int $version,
        public int $position,
        public \DateTimeImmutable $occurredAt,
        public string $subjectType,
        public string $subjectId,
        public array $data,
        public ?string $correlationId,
        public string $actorKind,
    ) {
    }
}
