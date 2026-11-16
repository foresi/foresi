<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Domain;

/**
 * What an endpoint subscribes to: event names of the catalogue, or a whole
 * module as "module.*". A bare "*" is refused, so an endpoint never receives
 * every event of the installation, including those of modules added later.
 * Whether a name exists and its module may be read is checked when it is saved.
 */
final readonly class EventTypes
{
    private const string PATTERN = '/^[a-z][a-z0-9-]*\.(\*|[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*)$/';

    /**
     * @param list<string> $patterns
     */
    private function __construct(private array $patterns)
    {
    }

    /**
     * @param list<string> $patterns each kept once, in the order given
     */
    public static function of(array $patterns): self
    {
        if ([] === $patterns) {
            throw new \InvalidArgumentException('A webhook endpoint subscribes to at least one event type.');
        }
        foreach ($patterns as $pattern) {
            if (1 !== preg_match(self::PATTERN, $pattern)) {
                throw new \InvalidArgumentException('A webhook event type is an event name or a module followed by ".*".');
            }
        }

        return new self(array_values(array_unique($patterns)));
    }

    /**
     * @return list<string>
     */
    public function patterns(): array
    {
        return $this->patterns;
    }
}
