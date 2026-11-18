<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\View;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The counters of the installed modules, by the list they count.
 */
final readonly class ListRecordCounters
{
    /**
     * @param iterable<ListRecordCounter> $counters
     */
    public function __construct(
        #[AutowireIterator(ListRecordCounter::TAG)]
        private iterable $counters,
    ) {
    }

    public function of(string $listKey): ?ListRecordCounter
    {
        foreach ($this->counters as $counter) {
            if (\in_array($listKey, $counter->lists(), true)) {
                return $counter;
            }
        }

        return null;
    }
}
