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
use Foresi\CashFlow\Contract\FlowCertainty;
use Foresi\CashFlow\Contract\FlowDirection;
use Foresi\CashFlow\Contract\ForecastPeriod;
use Foresi\CashFlow\Domain\Checkpoint;
use Foresi\CashFlow\Domain\MinimumBalance;
use Foresi\ExchangeRate\Application\ExchangeRates;
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
 * left out (PlannedFulfilment); ForecastFlows asks the sources, as it does for
 * ForecastReads. A certainty filter keeps the flows of the certainties chosen,
 * and the balances, the lowest point and the shortfall follow it; the balance
 * today is never filtered. A source whose documents the caller may not
 * read adds to the category totals only, never as items (C15).
 * A source, a balance source or a rate that fails is listed and left out; it
 * never breaks the forecast.
 */
final class Forecasts
{
    public const int DEFAULT_MONTHS = 12;
    public const int MAX_MONTHS = 24;
    public const int STALE_AFTER_DAYS = 7;

    /** @var list<string> the keys of the sources whose documents the caller may not read */
    private array $restricted = [];

    private readonly ForecastFlows $flows;

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
        iterable $sources,
        private readonly iterable $balanceSources,
        iterable $actualSources = [],
    ) {
        $this->flows = new ForecastFlows($store, $logger, $sources, $actualSources);
    }

    /**
     * @param string|null              $from        the first day, today or later, ISO 8601; today when null
     * @param int|null                 $months      how many calendar months the horizon covers, 1 to 24; 12 when null
     * @param string|null              $weeksUntil  weeks are used up to the week holding this day, ISO 8601; the end of next month when null
     * @param list<FlowCertainty>|null $certainties the flows counted, by how sure they are; all when null. The balance today is never filtered.
     *
     * @throws CashFlowRefused
     */
    public function of(?string $from = null, ?int $months = null, ?string $weeksUntil = null, ?array $certainties = null): Forecast
    {
        $this->access->denyUnlessStaff();
        $firm = $this->access->firm();
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
        $czk = new CzkAmounts($this->exchangeRates, $this->logger, $today);
        $unavailable = [];

        $accounts = [];
        foreach ($this->access->accountsOf($firm) as $account) {
            $accounts[] = $this->opening($firm, $account, $today, $czk, $unavailable);
        }
        // In the order of FlowCertainty, each once.
        $certainties = null === $certainties ? null : array_values(array_filter(FlowCertainty::cases(), static fn (FlowCertainty $certainty): bool => \in_array($certainty, $certainties, true)));
        $collected = $this->flows->collect($firm, $today, $start, $end, $czk, certainties: $certainties);
        $lines = $collected->lines;
        $this->restricted = $collected->restricted;
        array_push($unavailable, ...$collected->unavailable);

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
            $czk->missing(),
            $this->restricted,
            $certainties,
        );
    }

    /**
     * The certainties a client names, such as "document" and "plan"; null when it names none.
     *
     * @param list<mixed> $names
     *
     * @return list<FlowCertainty>|null
     *
     * @throws CashFlowRefused invalid_certainty
     */
    public static function certainties(array $names): ?array
    {
        $certainties = [];
        foreach ($names as $name) {
            $certainty = \is_string($name) ? FlowCertainty::tryFrom(trim($name)) : null;
            if (!$certainty instanceof FlowCertainty) {
                throw CashFlowRefused::invalid('invalid_certainty', 'certainty', \sprintf('certainty is a list of %s.', implode(', ', FlowCertainty::values())));
            }
            $certainties[] = $certainty;
        }

        return [] === $certainties ? null : $certainties;
    }

    /**
     * @param list<string> $unavailable
     */
    private function opening(LegalEntity $firm, FirmBankAccount $account, \DateTimeImmutable $today, CzkAmounts $czk, array &$unavailable): AccountOpening
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

        return new AccountOpening($account, $balance, $czk->of($balance), $asOf, $source);
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
        $inByCertainty = [];
        $outByCertainty = [];
        foreach ($lines as $line) {
            $certainty = $line->flow->certainty->value;
            if (FlowDirection::In === $line->flow->direction) {
                $inByCertainty[$certainty] = isset($inByCertainty[$certainty]) ? $inByCertainty[$certainty]->plus($line->amount) : $line->amount;
            } else {
                $outByCertainty[$certainty] = isset($outByCertainty[$certainty]) ? $outByCertainty[$certainty]->plus($line->amount) : $line->amount;
            }
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

        return new PeriodForecast($period, $opening, $in, $out, $closing, $visible, array_values($hidden), $inByCertainty, $outByCertainty);
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
