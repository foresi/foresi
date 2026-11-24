<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Application;

use Foresi\Margin\Contract\MarginRevenueSource;
use Foresi\Margin\Contract\RevenueDocument;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The modules that give Margin their revenue documents, asked in turn.
 */
final readonly class RevenueSources
{
    /**
     * @param iterable<MarginRevenueSource> $sources
     */
    public function __construct(#[AutowireIterator(MarginRevenueSource::TAG)] private iterable $sources)
    {
    }

    /**
     * @return iterable<RevenueDocument>
     */
    public function between(\DateTimeImmutable $from, \DateTimeImmutable $to): iterable
    {
        foreach ($this->sources as $source) {
            yield from $source->between($from, $to);
        }
    }
}
