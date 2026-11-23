<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\CashFlow\Support;

use Foresi\CashFlow\Contract\CategorisedSource;
use Foresi\CashFlow\Contract\FlowCategory;

/**
 * A source of some categories that gives nothing and counts how often it is asked.
 */
final class FakeCategorisedSource implements CategorisedSource
{
    public int $asked = 0;

    /**
     * @param list<FlowCategory> $categories
     */
    public function __construct(private readonly string $key, private readonly array $categories)
    {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function categories(): array
    {
        return $this->categories;
    }

    public function mayReadDetails(): bool
    {
        return true;
    }

    public function flows(\DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $today): iterable
    {
        ++$this->asked;

        return [];
    }
}
