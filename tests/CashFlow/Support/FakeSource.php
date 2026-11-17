<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\CashFlow\Support;

use App\CashFlow\Contract\CashFlowSource;
use App\CashFlow\Contract\ExpectedFlow;

/**
 * A source that answers the flows it was given, or fails after the first one.
 */
final class FakeSource implements CashFlowSource
{
    /** @var list<array{string, string, string, string}> what it was asked: firm, from, to, today */
    public array $asked = [];

    /**
     * @param list<ExpectedFlow> $flows
     */
    public function __construct(private readonly string $key, private readonly array $flows = [], private readonly bool $fails = false)
    {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function flows(string $firmId, \DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $today): iterable
    {
        $this->asked[] = [$firmId, $from->format('Y-m-d'), $to->format('Y-m-d'), $today->format('Y-m-d e')];
        foreach ($this->flows as $flow) {
            yield $flow;
            if ($this->fails) {
                throw new \RuntimeException('The source broke.');
            }
        }
        if ($this->fails) {
            throw new \RuntimeException('The source broke.');
        }
    }
}
