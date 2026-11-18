<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\CashFlow\Support;

use Foresi\CashFlow\Contract\ActualFlow;
use Foresi\CashFlow\Contract\ActualFlowSource;

/**
 * An actual source that answers what it was given, or fails after the first one.
 */
final class FakeActualSource implements ActualFlowSource
{
    /** @var list<array{string, string, string}> what it was asked: firm, from, to */
    public array $asked = [];

    /**
     * @param list<ActualFlow> $actuals
     */
    public function __construct(private readonly string $key, private readonly array $actuals = [], private readonly bool $fails = false)
    {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function actuals(string $firmId, \DateTimeImmutable $from, \DateTimeImmutable $to): iterable
    {
        $this->asked[] = [$firmId, $from->format('Y-m-d'), $to->format('Y-m-d')];
        foreach ($this->actuals as $actual) {
            yield $actual;
            if ($this->fails) {
                throw new \RuntimeException('The source broke.');
            }
        }
    }
}
