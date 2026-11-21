<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\TaxesAndContributions\Support;

use Foresi\CashFlow\Contract\CashFlowSource;
use Foresi\TaxesAndContributions\Integration\CashFlow\EstimateFlows;

/**
 * The estimate source as the forecast asks it, counting how often.
 */
final class CountingSource implements CashFlowSource
{
    public int $calls = 0;

    public ?CashFlowSource $inner = null;

    public function key(): string
    {
        return EstimateFlows::KEY;
    }

    public function mayReadDetails(): bool
    {
        return true;
    }

    public function flows(\DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $today): iterable
    {
        ++$this->calls;

        return $this->inner?->flows($from, $to, $today) ?? [];
    }
}
