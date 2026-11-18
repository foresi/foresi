<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Application;

use Foresi\CashFlow\Contract\ActualFlowSource;
use Foresi\CashFlow\Contract\BalanceCheckpoint;
use Foresi\CashFlow\Contract\BalanceSource;
use Foresi\CashFlow\Contract\CashFlowSource;
use Foresi\CashFlow\Contract\ExpectedFlow;
use Foresi\CashFlow\Contract\FlowCertainty;
use Foresi\CashFlow\Contract\FlowDirection;
use Foresi\CashFlow\Contract\FlowFlag;
use Foresi\CashFlow\Contract\ForecastPeriod;
use Foresi\CashFlow\Domain\Checkpoint;
use Foresi\CashFlow\Domain\MinimumBalance;
use Foresi\CashFlow\Domain\PlannedItem;
use Foresi\ExchangeRate\Application\ExchangeRates;
use Foresi\ExchangeRate\Domain\ExchangeRate;
use Foresi\ExchangeRate\Domain\RateNotAvailable;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Party\Domain\FirmBankAccount;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Psr\Log\LoggerInterface;

/**
 * The forecast of a firm, computed on read from every source and the latest
 * balance of each of its bank accounts (C2, C6–C10, C14). "Today" is the
 * instance's today only. A flow still open before today is counted on the first
 * day and flagged overdue; a document with an expected day is counted then.
 * Every amount is converted to CZK at today's rate and keeps its own amount.
 * A planned occurrence that a document or a bank movement already fulfils is
 * left out (PlannedFulfilment). A source whose documents the caller may not
 * read adds to the category totals only, never as items (C15).
 * A source, a balance source or a rate that fails is listed and left out; it
 * never breaks the forecast.
 */
final class Forecasts
{
    public const int DEFAULT_MONTHS = 12;
    public const int MAX_MONTHS = 24;
    public const int STALE_AFTER_DAYS = 7;

    /** @var array<string, ExchangeRate> */
    private array $rates = [];
    /** @var list<string> the currencies without a rate in this forecast */
    private array $missingRates = [];
    /** @var list<string> the keys of the sources whose documents the caller may not read */
    private array $restricted = [];

    /**
     * @param iterable<CashFlowSource>   $sources
     * @param iterable<BalanceSource>    $balanceSources
     * @param iterable<ActualFlowSource> $actualSources
     */
    public function __construct(
        private readonly CashFlowAccess $access,
        private readonly CashFlowStore $store,
        private readonly InstanceClock $clock,
        private readonly ExchangeRates $exchangeRates,
        private readonly LoggerInterface $logger,
        private readonly iterable $sources,
        private readonly iterable $balanceSources,
        private readonly iterable $actualSources = [],
    ) {
    }

    /**
     * @param string|null $from       the first day, today or later, ISO 8601; today when null
     * @param int|null    $months     how many calendar months the horizon covers, 1 to 24; 12 when null
     * @param string|null $weeksUntil weeks are used up to the week holding this day, ISO 8601; the end of next month when null
     *
     * @throws CashFlowRefused
     */
    public function of(string $firmId, ?string $from = null, ?int $months = null, ?string $weeksUntil = null): Forecast
    {
        $this->access->denyUnlessStaff();
        $firm = $this->access->firm($firmId);
        $today = $this->clock->today();
        $zone = $today->getTimezone();
        $start = null === $from ? $today : PlannedItemRules::day($from, $zone, 'from');
        if ($start < $today) {
            throw CashFlowRefused::invalid('from_in_past', 'from', 'A forecast starts today or later; what happened before is in the balance.');
        }
        $months ??= self::DEFAULT_MONTHS;
        if ($months < 1 || $months > self::MAX_MONTHS) {
            throw CashFlowRefused::invalid('months_invalid', 'months', \sprintf('A forecast covers 1 to %d months.', self::MAX_MONTHS));
        }
        $weeksEnd = null === $weeksUntil ? ForecastPeriods::endOfNextMonth($start) : PlannedItemRules::day($weeksUntil, $zone, 'weeksUntil');
        $periods = ForecastPeriods::of($start, $months, $weeksEnd);
        $end = ForecastPeriods::horizonEnd($start, $months);
        $this->rates = [];
        $this->missingRates = [];
        $this->restricted = [];
        $unavailable = [];

        $accounts = [];
        foreach ($this->access->accountsOf($firm) as $account) {
            $accounts[] = $this->opening($firm, $account, $today, $unavailable);
        }
        $lines = $this->unfulfilled($firm, $this->lines($firm, $today, $start, $end, $unavailable), $unavailable);

        $running = $this->sum(array_map(static fn (AccountOpening $account): ?Money => $account->amount, $accounts));
        $opening = $running;
        foreach ($lines as $line) {
            if ($line->countedOn < $start) {
                $running = $running->plus($line->signed());
            }
        }
        $minimum = $this->store->minimumBalance($firm->id());
        $minimumAmount = $minimum instanceof MinimumBalance ? $minimum->amount() : null;
        $lowest = $running;
        $lowestOn = $start;
        $shortfallOn = $minimumAmount instanceof Money && $running->minor() < $minimumAmount->minor() ? $start : null;
        $results = [];
        foreach ($periods as $period) {
            $periodOpening = $running;
            $in = [];
            $out = [];
            $periodLines = array_values(array_filter($lines, static fn (ForecastLine $line): bool => $period->contains($line->countedOn)));
            foreach ($periodLines as $index => $line) {
                $category = $line->flow->category->value;
                if (FlowDirection::In === $line->flow->direction) {
                    $in[$category] = isset($in[$category]) ? $in[$category]->plus($line->amount) : $line->amount;
                } else {
                    $out[$category] = isset($out[$category]) ? $out[$category]->plus($line->amount) : $line->amount;
                }
                $running = $running->plus($line->signed());
                $next = $periodLines[$index + 1] ?? null;
                if ($next instanceof ForecastLine && $next->countedOn->format('Y-m-d') === $line->countedOn->format('Y-m-d')) {
                    continue;
                }
                // The balance at the end of the day.
                if ($running->minor() < $lowest->minor()) {
                    $lowest = $running;
                    $lowestOn = $line->countedOn;
                }
                if (null === $shortfallOn && $minimumAmount instanceof Money && $running->minor() < $minimumAmount->minor()) {
                    $shortfallOn = $line->countedOn;
                }
            }
            $results[] = $this->period($period, $periodOpening, $in, $out, $running, $periodLines);
        }
        $asOf = $this->oldest($accounts);

        return new Forecast(
            $firm,
            $today,
            $start,
            $opening,
            $accounts,
            $asOf,
            !$asOf instanceof \DateTimeImmutable || $asOf < $today->modify(\sprintf('-%d days', self::STALE_AFTER_DAYS)),
            $results,
            $lowest,
            $lowestOn,
            $minimumAmount,
            $shortfallOn,
            array_values(array_unique($unavailable)),
            $this->missingRates,
            array_values(array_unique($this->restricted)),
        );
    }

    /**
     * @param list<string> $unavailable
     */
    private function opening(LegalEntity $firm, FirmBankAccount $account, \DateTimeImmutable $today, array &$unavailable): AccountOpening
    {
        $checkpoint = $this->store->latestCheckpoint($account, $today);
        if (!$checkpoint instanceof Checkpoint) {
            return new AccountOpening($account, null, null, null, null);
        }
        $balance = $checkpoint->amount();
        $asOf = $checkpoint->on();
        $source = null;
        foreach ($this->balanceSources as $balanceSource) {
            try {
                $known = $balanceSource->balance(new BalanceCheckpoint($account->id(), $firm->id(), $balance, $asOf), $today);
            } catch (\Throwable $failure) {
                $this->logger->error('The balance source {source} failed.', ['source' => $balanceSource->key(), 'exception' => $failure]);
                $unavailable[] = $balanceSource->key();
                continue;
            }
            if (null !== $known) {
                [$balance, $asOf, $source] = [$known->amount, $known->asOf, $balanceSource->key()];
                break;
            }
        }

        return new AccountOpening($account, $balance, $this->czk($balance, $today), $asOf, $source);
    }

    /**
     * @param list<string> $unavailable
     *
     * @return list<ForecastLine> in the order they are counted
     */
    private function lines(LegalEntity $firm, \DateTimeImmutable $today, \DateTimeImmutable $start, \DateTimeImmutable $end, array &$unavailable): array
    {
        $expected = $this->store->expectedDates();
        $lines = [];
        foreach ($this->sources as $source) {
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
                $this->restricted[] = $source->key();
            }
            foreach ($flows as $flow) {
                $line = $this->line($flow, $expected, $today, $start, $end);
                if ($line instanceof ForecastLine) {
                    $lines[] = $line;
                }
            }
        }
        // By the day they are counted on, overdue ones first, money in before money out.
        usort($lines, static fn (ForecastLine $a, ForecastLine $b): int => self::order($a) <=> self::order($b));

        return $lines;
    }

    /**
     * @param array<string, \DateTimeImmutable> $expected
     */
    private function line(ExpectedFlow $flow, array $expected, \DateTimeImmutable $today, \DateTimeImmutable $start, \DateTimeImmutable $end): ?ForecastLine
    {
        $override = $expected[$flow->subject->type.':'.$flow->subject->id] ?? null;
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
        $amount = $this->czk($flow->amount, $today);

        return $amount instanceof Money ? new ForecastLine($flow, $amount, $overdue ? $start : new \DateTimeImmutable($day, $today->getTimezone())) : null;
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
            if (!$item instanceof PlannedItem || (!$item->isRecurring() || !$item->counterparty() instanceof \Foresi\Party\Domain\Party) && $item->matchRule()->isEmpty()) {
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
     * A period with the items the caller may read; the lines of a restricted
     * source are summed by source, direction and category instead.
     *
     * @param array<string, Money> $in
     * @param array<string, Money> $out
     * @param list<ForecastLine>   $lines
     */
    private function period(ForecastPeriod $period, Money $opening, array $in, array $out, Money $closing, array $lines): PeriodForecast
    {
        $visible = [];
        $hidden = [];
        foreach ($lines as $line) {
            if (!\in_array($line->flow->source, $this->restricted, true)) {
                $visible[] = $line;
                continue;
            }
            $key = $line->flow->source.'|'.$line->flow->direction->value.'|'.$line->flow->category->value;
            $hidden[$key] = isset($hidden[$key])
                ? new HiddenTotal($line->flow->source, $line->flow->direction, $line->flow->category, $hidden[$key]->amount->plus($line->amount), $hidden[$key]->count + 1)
                : new HiddenTotal($line->flow->source, $line->flow->direction, $line->flow->category, $line->amount, 1);
        }
        ksort($hidden);

        return new PeriodForecast($period, $opening, $in, $out, $closing, $visible, array_values($hidden));
    }

    /**
     * The amount in CZK at today's rate; null when the currency has no rate.
     */
    private function czk(Money $money, \DateTimeImmutable $today): ?Money
    {
        $currency = $money->currency();
        if (Currency::CZK === $currency) {
            return $money;
        }
        if (\in_array($currency->value, $this->missingRates, true)) {
            return null;
        }
        if (!isset($this->rates[$currency->value])) {
            try {
                $this->rates[$currency->value] = $this->exchangeRates->rateOn($currency->value, $today);
            } catch (RateNotAvailable $failure) {
                $this->logger->warning('No rate of {currency} for the cash-flow forecast.', ['currency' => $currency->value, 'exception' => $failure]);
                $this->missingRates[] = $currency->value;

                return null;
            }
        }
        $rate = $this->rates[$currency->value];
        $czk = Money::ofMinor($money->minor(), Currency::CZK)->multipliedBy($rate->rate);

        return $czk->dividedBy((string) $rate->amount);
    }

    /**
     * @return array{string, bool, string, string}
     */
    private static function order(ForecastLine $line): array
    {
        return [$line->countedOn->format('Y-m-d'), !$line->flow->hasFlag(FlowFlag::Overdue), $line->flow->direction->value, $line->flow->label];
    }

    /**
     * @param list<Money|null> $amounts
     */
    private function sum(array $amounts): Money
    {
        $sum = Money::ofMinor(0, Currency::CZK);
        foreach ($amounts as $amount) {
            if ($amount instanceof Money) {
                $sum = $sum->plus($amount);
            }
        }

        return $sum;
    }

    /**
     * @param list<AccountOpening> $accounts
     */
    private function oldest(array $accounts): ?\DateTimeImmutable
    {
        $known = array_filter(array_map(static fn (AccountOpening $account): ?\DateTimeImmutable => $account->asOf, $accounts));

        return [] === $known ? null : min($known);
    }
}
