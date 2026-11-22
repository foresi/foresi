<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Application;

use Foresi\CashFlow\Contract\ActualFlowSource;
use Foresi\CashFlow\Contract\FoundMovement;
use Foresi\CashFlow\Contract\FoundMovements;
use Foresi\CashFlow\Contract\MovementFinder;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The bank movements of the actual sources that meet match rules (C16). Each
 * movement meets the first rule it meets, in the given order. A source that
 * fails is logged and listed as unavailable; what it gave before failing may be
 * partial, so none of it is kept.
 */
final readonly class RuleMovements implements MovementFinder
{
    /**
     * @param iterable<ActualFlowSource> $actualSources
     */
    public function __construct(
        private iterable $actualSources = [],
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function find(\DateTimeImmutable $from, \DateTimeImmutable $to, array $rules): FoundMovements
    {
        if ([] === $rules) {
            return new FoundMovements([]);
        }
        $movements = [];
        $unavailable = [];
        foreach ($this->actualSources as $source) {
            try {
                $found = [];
                foreach ($source->actuals($from, $to) as $actual) {
                    foreach ($rules as $rule) {
                        if ($rule->isMetBy($actual)) {
                            $found[] = new FoundMovement($rule->key, $actual->subject->id, $actual->on, $actual->amount, $actual->direction);

                            break;
                        }
                    }
                }
            } catch (\Throwable $failure) {
                $this->logger->error('The cash-flow actual source {source} failed.', ['source' => $source->key(), 'exception' => $failure]);
                $unavailable[] = $source->key();

                continue;
            }
            array_push($movements, ...$found);
        }
        usort($movements, static fn (FoundMovement $a, FoundMovement $b): int => [$a->on->format('Y-m-d'), $a->id] <=> [$b->on->format('Y-m-d'), $b->id]);

        return new FoundMovements($movements, array_values(array_unique($unavailable)));
    }
}
