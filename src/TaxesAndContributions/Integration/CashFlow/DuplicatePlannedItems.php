<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Integration\CashFlow;

use Foresi\CashFlow\Contract\FlowCategory;
use Foresi\CashFlow\Contract\FlowCertainty;
use Foresi\CashFlow\Contract\FlowDirection;
use Foresi\CashFlow\Contract\ForecastQuery;
use Foresi\CashFlow\Contract\ForecastReader;
use Foresi\Instance\Application\InstanceClock;
use Foresi\TaxesAndContributions\Contract\PlannedLevySource;
use Foresi\TaxesAndContributions\Contract\PossibleDuplicate;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The planned items entered by hand for taxes and insurance, which the
 * estimate flows may count a second time (B5): those the forecast still
 * expects in the next twelve months, read without this module's own source.
 * It only reads; the owner ends them in Cash flow.
 */
final readonly class DuplicatePlannedItems implements PlannedLevySource
{
    public const int MONTHS = 12;

    private const array LEVIES = [FlowCategory::Taxes, FlowCategory::Insurance];

    public function __construct(
        private ForecastReader $reader,
        private InstanceClock $clock,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function activeLevyItems(): array
    {
        $today = $this->clock->today();
        $others = array_values(array_filter(FlowCategory::cases(), static fn (FlowCategory $category): bool => !\in_array($category, self::LEVIES, true)));
        $read = $this->reader->read(new ForecastQuery($today, $today->modify(\sprintf('+%d months', self::MONTHS)), [EstimateFlows::KEY], $others, [FlowCertainty::Plan]));
        $items = [];
        foreach ($read->flows as $flow) {
            $subject = $flow->subject;
            if (null === $subject || 'planned_item' !== $subject->type || isset($items[$subject->id])) {
                continue;
            }
            $route = FlowDirection::In === $flow->direction ? 'app_cash_flow_planned_income_item' : 'app_cash_flow_planned_costs_item';
            $items[$subject->id] = new PossibleDuplicate($subject->id, $flow->label ?? $subject->id, $flow->category->value, $this->urls->generate($route, ['id' => $subject->id]));
        }

        return array_values($items);
    }
}
