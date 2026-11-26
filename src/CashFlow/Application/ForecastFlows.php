<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Application;

use Foresi\CashFlow\Contract\ActualFlowSource;
use Foresi\CashFlow\Contract\CashFlowSource;
use Foresi\CashFlow\Contract\CategorisedSource;
use Foresi\CashFlow\Contract\ExpectedFlow;
use Foresi\CashFlow\Contract\FlowCategory;
use Foresi\CashFlow\Contract\FlowCertainty;
use Foresi\CashFlow\Contract\FlowFlag;
use Foresi\CashFlow\Contract\UndatedFlow;
use Foresi\CashFlow\Contract\UndatedFlowSource;
use Foresi\CashFlow\Domain\PlannedItem;
use Foresi\Party\Domain\Party;
use Foresi\Shared\Domain\Money;
use Psr\Log\LoggerInterface;

/**
 * How the forecast asks its sources, shared by Forecasts and ForecastReads so
 * both count the same flows: every source but those left out, from today to
 * the last day; a document on the day it is expected on when someone set one;
 * a flow still open before today on the first day, flagged overdue unless its
 * source flagged it awaited; every
 * amount in CZK at today's rate; and no planned occurrence that a document or
 * a bank movement already fulfils (PlannedFulfilment).
 *
 * A source left out is never called. A source or an actual source that fails
 * is logged, listed and left out. When asked, the undated flows of an
 * UndatedFlowSource are collected too, through the same filters, and kept
 * apart: they have no day to be counted on.
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
     * @param \DateTimeImmutable       $start             the first day counted; a flow dated before today is counted on it
     * @param \DateTimeImmutable       $end               the last day, inclusive
     * @param list<string>             $excludeSources    the keys of the sources never to call
     * @param list<FlowCategory>       $excludeCategories
     * @param list<FlowCertainty>|null $certainties       the certainties kept; null keeps them all
     * @param string                   $text              the flows kept are those whose label or subject's label holds it, whatever its case; empty keeps them all
     * @param bool                     $undated           whether to collect the undated flows too
     */
    public function collect(
        \DateTimeImmutable $today,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        CzkAmounts $czk,
        array $excludeSources = [],
        array $excludeCategories = [],
        ?array $certainties = null,
        string $text = '',
        bool $undated = false,
    ): CollectedFlows {
        $text = mb_strtolower(trim($text));
        $unavailable = [];
        $restricted = [];
        $expected = $this->store->expectedDates();
        $lines = [];
        $undatedLines = [];
        foreach ($this->sources as $source) {
            // Asked for its key only: a source left out is never asked for its flows,
            // nor one whose categories are all left out.
            if (\in_array($source->key(), $excludeSources, true) || $this->allLeftOut($source, $excludeCategories)) {
                continue;
            }
            try {
                $flows = [];
                $details = $source->mayReadDetails();
                foreach ($source->flows($today, $end, $today) as $flow) {
                    $flows[] = $flow;
                }
                $undatedFlows = [];
                if ($undated && $source instanceof UndatedFlowSource) {
                    foreach ($source->undatedFlows($today) as $flow) {
                        $undatedFlows[] = $flow;
                    }
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
                if ('' !== $text && !str_contains(mb_strtolower($flow->label.' '.($flow->subject->label ?? '')), $text)) {
                    continue;
                }
                $line = $this->line($flow, $expected, $today, $start, $end, $czk);
                if ($line instanceof ForecastLine) {
                    $lines[] = $line;
                }
            }
            foreach ($undatedFlows as $flow) {
                if (!$this->kept($flow, $excludeCategories, $certainties, $text)) {
                    continue;
                }
                $amount = $czk->of($flow->amount);
                if ($amount instanceof Money) {
                    $undatedLines[] = new UndatedLine($flow, $amount);
                }
            }
        }
        // The largest first, as nothing orders them by a day.
        usort($undatedLines, static fn (UndatedLine $a, UndatedLine $b): int => [$b->amount->minor(), $a->flow->label] <=> [$a->amount->minor(), $b->flow->label]);
        // By the day they are counted on, overdue and awaited ones first, money in before money out.
        usort($lines, static fn (ForecastLine $a, ForecastLine $b): int => self::order($a) <=> self::order($b));
        $lines = $this->unfulfilled($lines, $unavailable);

        return new CollectedFlows($lines, array_values(array_unique($unavailable)), array_values(array_unique($restricted)), $undatedLines);
    }

    /**
     * Whether the filters keep an undated flow, as they keep a dated one.
     *
     * @param list<FlowCategory>       $excludeCategories
     * @param list<FlowCertainty>|null $certainties
     */
    private function kept(UndatedFlow $flow, array $excludeCategories, ?array $certainties, string $text): bool
    {
        if (\in_array($flow->category, $excludeCategories, true) || (null !== $certainties && !\in_array($flow->certainty, $certainties, true))) {
            return false;
        }

        return '' === $text || str_contains(mb_strtolower($flow->label.' '.($flow->subject->label ?? '')), $text);
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
        $past = $day < $today->format('Y-m-d');
        // A flow its source still awaits is past but not late: counted first, never overdue.
        if ($past && !$flow->hasFlag(FlowFlag::Awaited)) {
            $flow = $flow->movedTo($flow->expectedOn, FlowFlag::Overdue);
        }
        $amount = $czk->of($flow->amount);

        return $amount instanceof Money ? new ForecastLine($flow, $amount, $past ? $start : new \DateTimeImmutable($day, $today->getTimezone()), $sourceOn) : null;
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
    private function unfulfilled(array $lines, array &$unavailable): array
    {
        $items = [];
        foreach ($this->store->plannedItems() as $item) {
            $items[$item->id()] = $item;
        }
        $paired = $this->pairedOccurrences($lines);
        $planned = [];
        $first = null;
        $last = null;
        foreach ($lines as $index => $line) {
            $item = 'planned_item' === $line->flow->subject->type ? ($items[$line->flow->subject->id] ?? null) : null;
            // An occurrence a document is paired with is fulfilled, whatever the item says of its counterparty.
            if ($item instanceof PlannedItem && isset($paired[$item->id().'|'.$line->flow->expectedOn->format('Y-m-d')])) {
                unset($lines[$index]);
                continue;
            }
            if (!$item instanceof PlannedItem || (!$item->isRecurring() || !$item->counterparty() instanceof Party) && $item->matchRule()->isEmpty()) {
                continue;
            }
            $planned[$index] = $item;
            [$from, $to] = PlannedFulfilment::period($item, $line->flow->expectedOn);
            $first = null === $first || $from < $first ? $from : $first;
            $last = null === $last || $to > $last ? $to : $last;
        }
        if (!$first instanceof \DateTimeImmutable || !$last instanceof \DateTimeImmutable) {
            return array_values($lines);
        }
        $actuals = [];
        foreach ($this->actualSources as $source) {
            try {
                $given = [];
                foreach ($source->actuals($first, $last) as $actual) {
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
            if (PlannedFulfilment::isFulfilled($item, $lines[$index]->flow->expectedOn, $actuals, $lines[$index]->flow->amount)) {
                unset($lines[$index]);
            }
        }

        return array_values($lines);
    }

    /**
     * The occurrences with an auto or confirmed pairing in the days the planned lines fall on, read in one
     * query: "item id|day" for each.
     *
     * @param list<ForecastLine> $lines
     *
     * @return array<string, true>
     */
    private function pairedOccurrences(array $lines): array
    {
        $first = null;
        $last = null;
        foreach ($lines as $line) {
            if ('planned_item' !== $line->flow->subject->type) {
                continue;
            }
            $day = $line->flow->expectedOn;
            $first = null === $first || $day < $first ? $day : $first;
            $last = null === $last || $day > $last ? $day : $last;
        }
        if (!$first instanceof \DateTimeImmutable || !$last instanceof \DateTimeImmutable) {
            return [];
        }
        $paired = [];
        foreach ($this->store->pairedOccurrences($first, $last) as $pairing) {
            $paired[$pairing->plannedItemId().'|'.$pairing->occurrenceOn()?->format('Y-m-d')] = true;
        }

        return $paired;
    }

    /**
     * Whether every category a source's flows may fall in is left out.
     *
     * @param list<FlowCategory> $excludeCategories
     */
    private function allLeftOut(CashFlowSource $source, array $excludeCategories): bool
    {
        if (!$source instanceof CategorisedSource) {
            return false;
        }

        return array_all($source->categories(), fn (FlowCategory $category): bool => \in_array($category, $excludeCategories, true));
    }

    /**
     * @return array{string, bool, string, string}
     */
    private static function order(ForecastLine $line): array
    {
        return [$line->countedOn->format('Y-m-d'), !$line->flow->hasFlag(FlowFlag::Overdue) && !$line->flow->hasFlag(FlowFlag::Awaited), $line->flow->direction->value, $line->flow->label];
    }
}
