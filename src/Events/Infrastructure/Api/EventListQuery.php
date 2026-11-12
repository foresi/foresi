<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Events\Infrastructure\Api;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The query string of GET /api/v1/events. Without types, the feed holds every
 * event the caller may read.
 */
final readonly class EventListQuery
{
    public function __construct(
        #[Assert\Length(max: 2000)]
        public ?string $types = null,
        #[Assert\Range(min: 1, max: 100)]
        public int $limit = 25,
        public ?string $cursor = null,
    ) {
    }

    /**
     * @return list<string> the names and "module.*" patterns asked for; none when the parameter is left out
     */
    public function patterns(): array
    {
        if (null === $this->types) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(trim(...), explode(',', $this->types)), static fn (string $pattern): bool => '' !== $pattern)));
    }
}
