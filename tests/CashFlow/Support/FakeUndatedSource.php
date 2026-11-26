<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\CashFlow\Support;

use Foresi\CashFlow\Contract\ExpectedFlow;
use Foresi\CashFlow\Contract\UndatedFlow;
use Foresi\CashFlow\Contract\UndatedFlowSource;

/**
 * A source that answers the dated and the undated flows it was given, and
 * counts how often it was asked for the undated ones.
 */
final class FakeUndatedSource implements UndatedFlowSource
{
    public int $undatedAsked = 0;

    /**
     * @param list<UndatedFlow>  $undated
     * @param list<ExpectedFlow> $flows
     */
    public function __construct(private readonly string $key, private readonly array $undated = [], private readonly array $flows = [], private readonly bool $details = true)
    {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function mayReadDetails(): bool
    {
        return $this->details;
    }

    public function flows(\DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $today): iterable
    {
        yield from $this->flows;
    }

    public function undatedFlows(\DateTimeImmutable $today): iterable
    {
        ++$this->undatedAsked;

        yield from $this->undated;
    }
}
