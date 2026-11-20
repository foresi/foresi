<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Application;

use Foresi\CashFlow\Contract\ActualFlowSource;
use Foresi\CashFlow\Contract\CashFlowSource;
use Foresi\CashFlow\Contract\ForecastQuery;
use Foresi\CashFlow\Contract\ForecastRead;
use Foresi\CashFlow\Contract\ForecastReader;
use Foresi\CashFlow\Contract\ReadFlow;
use Foresi\ExchangeRate\Application\ExchangeRates;
use Foresi\Instance\Application\InstanceClock;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The flows of the forecast for another module (ForecastReader), asked of the
 * sources by ForecastFlows exactly as Forecasts asks them. A source the query
 * excludes is never called, so a module that is a source itself reads the
 * forecast without being asked again. A flow of a source whose documents the
 * caller may not read comes back without its subject, so totals stay right.
 * Only the owners and members of the organisation read it.
 */
final readonly class ForecastReads implements ForecastReader
{
    private ForecastFlows $flows;

    /**
     * @param iterable<CashFlowSource>   $sources
     * @param iterable<ActualFlowSource> $actualSources
     */
    public function __construct(
        private CashFlowAccess $access,
        CashFlowStore $store,
        private InstanceClock $clock,
        private ExchangeRates $exchangeRates,
        private LoggerInterface $logger = new NullLogger(),
        iterable $sources = [],
        iterable $actualSources = [],
    ) {
        $this->flows = new ForecastFlows($store, $logger, $sources, $actualSources);
    }

    /**
     * @throws CashFlowRefused forbidden; from_in_past, to_before_from or to_too_far; not_found without a firm
     */
    public function read(ForecastQuery $query): ForecastRead
    {
        $this->access->denyUnlessStaff();
        $this->access->firm();
        $today = $this->clock->today();
        $zone = $today->getTimezone();
        $from = new \DateTimeImmutable($query->from->format('Y-m-d'), $zone);
        $to = new \DateTimeImmutable($query->to->format('Y-m-d'), $zone);
        if ($from < $today) {
            throw CashFlowRefused::invalid('from_in_past', 'from', 'A forecast starts today or later; what happened before is in the balance.');
        }
        if ($to < $from) {
            throw CashFlowRefused::invalid('to_before_from', 'to', 'The last day of a forecast read is on or after its first day.');
        }
        if ($to > $today->modify(\sprintf('+%d months', Forecasts::MAX_MONTHS))) {
            throw CashFlowRefused::invalid('to_too_far', 'to', \sprintf('A forecast reaches at most %d months from today.', Forecasts::MAX_MONTHS));
        }
        $czk = new CzkAmounts($this->exchangeRates, $this->logger, $today);
        $collected = $this->flows->collect($today, $from, $to, $czk, $query->excludeSources, $query->excludeCategories, $query->certainties);
        $flows = [];
        foreach ($collected->lines as $line) {
            // What falls between today and the first day happened before the read, as the opening of a forecast holds it.
            if ($line->countedOn < $from) {
                continue;
            }
            $flow = $line->flow;
            $flows[] = new ReadFlow(
                $flow->source,
                \in_array($flow->source, $collected->restricted, true) ? null : $flow->subject,
                $flow->direction,
                $flow->amount,
                $line->amount,
                $line->countedOn,
                $flow->category,
                $flow->certainty,
                $flow->flags,
            );
        }

        return new ForecastRead($flows, $collected->unavailable, $collected->restricted, [] !== $collected->unavailable || [] !== $czk->missing());
    }
}
