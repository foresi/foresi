<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Integration\CashFlow;

use Foresi\CashFlow\Contract\FlowCategory;
use Foresi\CashFlow\Contract\FlowCertainty;
use Foresi\CashFlow\Contract\FlowDirection;
use Foresi\CashFlow\Contract\ForecastQuery;
use Foresi\CashFlow\Contract\ForecastReader;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Vat\Contract\PlannedVatSource;
use Foresi\Vat\Contract\PossibleDuplicate;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The planned items entered by hand for the VAT, which the flows of the VAT
 * periods may count a second time: those the forecast still expects in the
 * next twelve months, read without this module's own source. It only reads;
 * a person ends them in Cash flow.
 */
final readonly class DuplicateVatItems implements PlannedVatSource
{
    public const int MONTHS = 12;

    public function __construct(
        private ForecastReader $reader,
        private InstanceClock $clock,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function activeVatItems(): array
    {
        $today = $this->clock->today();
        $others = array_values(array_filter(FlowCategory::cases(), static fn (FlowCategory $category): bool => FlowCategory::Vat !== $category));
        $read = $this->reader->read(new ForecastQuery($today, $today->modify(\sprintf('+%d months', self::MONTHS)), [VatFlows::KEY], $others, [FlowCertainty::Plan]));
        $items = [];
        foreach ($read->flows as $flow) {
            $subject = $flow->subject;
            if (null === $subject || 'planned_item' !== $subject->type || isset($items[$subject->id])) {
                continue;
            }
            $route = FlowDirection::In === $flow->direction ? 'app_cash_flow_planned_income_item' : 'app_cash_flow_planned_costs_item';
            $items[$subject->id] = new PossibleDuplicate($subject->id, $flow->label ?? $subject->id, $this->urls->generate($route, ['id' => $subject->id]));
        }

        return array_values($items);
    }
}
