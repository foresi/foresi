<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Calendar\Support;

use Foresi\Calendar\Contract\CalendarEntry;
use Foresi\Calendar\Contract\CalendarEntrySource;

/**
 * A source with fixed entries per person, or one that breaks.
 */
final class FakeEntrySource implements CalendarEntrySource
{
    /** @var list<array{string, string, string}> what it was asked: the person, from and to */
    public array $asked = [];

    /**
     * @param array<string, list<CalendarEntry>> $entries by user id
     */
    public function __construct(private readonly string $key, private readonly array $entries = [], private readonly bool $fails = false)
    {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function entriesFor(string $userId, \DateTimeImmutable $from, \DateTimeImmutable $to): iterable
    {
        $this->asked[] = [$userId, $from->format('Y-m-d H:i e'), $to->format('Y-m-d H:i e')];
        if ($this->fails) {
            throw new \RuntimeException('The source broke.');
        }

        return $this->entries[$userId] ?? [];
    }
}
