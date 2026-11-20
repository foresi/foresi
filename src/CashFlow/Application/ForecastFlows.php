<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Application;

use Foresi\CashFlow\Contract\ActualFlowSource;
use Foresi\CashFlow\Contract\CashFlowSource;
use Foresi\CashFlow\Contract\ExpectedFlow;
use Foresi\CashFlow\Contract\FlowCategory;
use Foresi\CashFlow\Contract\FlowCertainty;
use Foresi\CashFlow\Contract\FlowFlag;
use Foresi\CashFlow\Domain\PlannedItem;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\Party;
use Foresi\Shared\Domain\Money;
use Psr\Log\LoggerInterface;

/**
 * How the forecast asks its sources, shared by Forecasts and ForecastReads so
 * both count the same flows: every source but those left out, from today to
 * the last day; a document on the day it is expected on when someone set one;
 * a flow still open before today on the first day, flagged overdue; every
 * amount in CZK at today's rate; and no planned occurrence that a document or
 * a bank movement already fulfils (PlannedFulfilment).
 *
 * A source left out is never called. A source or an actual source that fails
 * is logged, listed and left out.
 */
final readonly class ForecastFlows
{
    /**
     * @param iterable<CashFlowSource>   $sources
     * @param iterable<ActualFlowSource> $actualSources
     */
    public function __construct(
        private CashFlowStore $store,
        private LoggerInterface $logger,
        private iterable $sources,
        private iterable $actualSources = [],
    ) {
    }

    /**
     * @param \DateTimeImmutable       $start             the first day counted; a flow overdue before today is counted on it
     * @param \DateTimeImmutable       $end               the last day, inclusive
     * @param list<string>             $excludeSources    the keys of the sources never to call
     * @param list<FlowCategory>       $excludeCategories
     * @param list<FlowCertainty>|null $certainties       the certainties kept; null keeps them all
     */
    public function collect(
        LegalEntity $firm,
        \DateTimeImmutable $today,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        CzkAmounts $czk,
        array $excludeSources = [],
        array $excludeCategories = [],
        ?array $certainties = null,
    ): CollectedFlows {
        $unavailable = [];
        $restricted = [];
        $expected = $this->store->expectedDates();
        $lines = [];
        foreach ($this->sources as $source) {
            // Asked for its key only: a source left out is never asked for its flows.
            if (\in_array($source->key(), $excludeSources, true)) {
                continue;
            }
            try {
                $flows = [];
                $details = $source->mayReadDetails();
                foreach ($source->flows($firm->id(), $today, $end, $today) as $flow) {
                    $flows[] = $flow;
                }
            } catch (\Throwable $failure) {
                $this->logger->error('The cash-flow source {source} failed.', ['source' => $source->key(), 'exception' => $failure]);
                $unavailable[] = $source->key();
                continue;
            }
            if (!$details) {
                $restricted[] = $source->key();
            }
            foreach ($flows as $flow) {
                if (\in_array($flow->category, $excludeCategories, true) || (null !== $certainties && !\in_array($flow->certainty, $certainties, true))) {
                    continue;
                }
                $line = $this->line($flow, $expected, $today, $start, $end, $czk);
                if ($line instanceof ForecastLine) {
                    $lines[] = $line;
                }
            }
        }
        // By the day they are counted on, overdue ones first, money in before money out.
        usort($lines, static fn (ForecastLine $a, ForecastLine $b): int => self::order($a) <=> self::order($b));
        $lines = $this->unfulfilled($firm, $lines, $unavailable);

        return new CollectedFlows($lines, array_values(array_unique($unavailable)), array_values(array_unique($restricted)));
    }

    /**
     * @param array<string, \DateTimeImmutable> $expected
     */
    private function line(ExpectedFlow $flow, array $expected, \DateTimeImmutable $today, \DateTimeImmutable $start, \DateTimeImmutable $end, CzkAmounts $czk): ?ForecastLine
    {
        $override = $expected[$flow->subject->type.':'.$flow->subject->id] ?? null;
        $sourceOn = $flow->expectedOn;
        if (FlowCertainty::Document === $flow->certainty && $override instanceof \DateTimeImmutable) {
            $flow = $flow->movedTo(new \DateTimeImmutable($override->format('Y-m-d'), $today->getTimezone()), FlowFlag::ExpectedDate);
        }
        $day = $flow->expectedOn->format('Y-m-d');
        if ($day > $end->format('Y-m-d')) {
            return null;
        }
        $overdue = $day < $today->format('Y-m-d');
        if ($overdue) {
            $flow = $flow->movedTo($flow->expectedOn, FlowFlag::Overdue);
        }
        $amount = $czk->of($flow->amount);

        return $amount instanceof Money ? new ForecastLine($flow, $amount, $overdue ? $start : new \DateTimeImmutable($day, $today->getTimezone()), $sourceOn) : null;
    }

    /**
     * The lines but the planned occurrences that a document or a bank movement
     * already fulfils, asked of the actual sources once for all the periods.
     *
     * @param list<ForecastLine> $lines
     * @param list<string>       $unavailable
     *
     * @return list<ForecastLine>
     */
    private function unfulfilled(LegalEntity $firm, array $lines, array &$unavailable): array
    {
        $items = [];
        foreach ($this->store->plannedItemsOf($firm->id()) as $item) {
            $items[$item->id()] = $item;
        }
        $planned = [];
        $first = null;
        $last = null;
        foreach ($lines as $index => $line) {
            $item = 'planned_item' === $line->flow->subject->type ? ($items[$line->flow->subject->id] ?? null) : null;
            if (!$item instanceof PlannedItem || (!$item->isRecurring() || !$item->counterparty() instanceof Party) && $item->matchRule()->isEmpty()) {
                continue;
            }
            $planned[$index] = $item;
            [$from, $to] = PlannedFulfilment::period($item, $line->flow->expectedOn);
            $first = null === $first || $from < $first ? $from : $first;
            $last = null === $last || $to > $last ? $to : $last;
        }
        if (!$first instanceof \DateTimeImmutable || !$last instanceof \DateTimeImmutable) {
            return $lines;
        }
        $actuals = [];
        foreach ($this->actualSources as $source) {
            try {
                $given = [];
                foreach ($source->actuals($firm->id(), $first, $last) as $actual) {
                    $given[] = $actual;
                }
            } catch (\Throwable $failure) {
                // What it gave before failing may be partial; it suppresses nothing.
                $this->logger->error('The cash-flow actual source {source} failed.', ['source' => $source->key(), 'exception' => $failure]);
                $unavailable[] = $source->key();
                continue;
            }
            array_push($actuals, ...$given);
        }
        foreach ($planned as $index => $item) {
            if (PlannedFulfilment::isFulfilled($item, $lines[$index]->flow->expectedOn, $actuals)) {
                unset($lines[$index]);
            }
        }

        return array_values($lines);
    }

    /**
     * @return array{string, bool, string, string}
     */
    private static function order(ForecastLine $line): array
    {
        return [$line->countedOn->format('Y-m-d'), !$line->flow->hasFlag(FlowFlag::Overdue), $line->flow->direction->value, $line->flow->label];
    }
}
