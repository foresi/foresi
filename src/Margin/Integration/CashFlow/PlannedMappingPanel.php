<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Integration\CashFlow;

use Foresi\CashFlow\Contract\CashFlowSlots;
use Foresi\CashFlow\Contract\FlowDirection;
use Foresi\CashFlow\Contract\PlannedItemDirectory;
use Foresi\Margin\Application\AllocationReads;
use Foresi\Margin\Application\MappingView;
use Foresi\Margin\Application\PlannedAllocationVersionView;
use Foresi\Margin\Application\TermView;
use Foresi\Margin\Domain\MarginRefused;
use Foresi\Shared\Domain\Money;
use Foresi\Ui\Contract\Slot\SlotItem;
use Foresi\Ui\Contract\Slot\SlotProvider;
use Foresi\Ui\Format\MoneyText;

/**
 * The "Split among clients" panel of the page of a recurring planned cost: the mapping in force with the month it
 * applies from, and the versions before and after it folded below. Nothing is offered for planned income or a
 * one-off cost, nor to a person who may not work with the margin.
 */
final readonly class PlannedMappingPanel implements SlotProvider
{
    public const string TEMPLATE = '@Margin/cash_flow/_panel.html.twig';

    /** After the paired documents, which Cash flow shows itself. */
    private const int WEIGHT = 20;

    public function __construct(private PlannedItemDirectory $items, private AllocationReads $reads, private MoneyText $text)
    {
    }

    public function items(string $slot, array $context): iterable
    {
        $itemId = $context['plannedItemId'] ?? null;
        if (CashFlowSlots::PLANNED_ITEM_SECTIONS !== $slot || !\is_string($itemId)) {
            return [];
        }
        $item = $this->items->one($itemId);
        if (!$item instanceof \Foresi\CashFlow\Contract\PlannedItemSummary || FlowDirection::Out !== $item->direction || !$item->recurring) {
            return [];
        }
        try {
            $view = $this->reads->planned($itemId);
        } catch (MarginRefused) {
            return [];
        }
        $versions = [];
        $placed = 'later';
        foreach ($view->versions as $version) {
            // Latest first: what comes before the version in force starts later, what comes after it is earlier.
            $placed = $version->current ? 'current' : ('current' === $placed ? 'earlier' : $placed);
            $versions[] = $this->version($version, $placed);
        }
        $current = array_find($versions, static fn (array $version): bool => 'current' === $version['placed']);

        return [new SlotItem(self::TEMPLATE, [
            'current' => $current,
            'others' => array_values(array_filter($versions, static fn (array $version): bool => 'current' !== $version['placed'])),
        ], self::WEIGHT)];
    }

    /**
     * @return array{month: string, placed: string, kind: ?string, terms: list<array<string, mixed>>}
     */
    private function version(PlannedAllocationVersionView $version, string $placed): array
    {
        $mapping = $version->mapping;

        return [
            'month' => $version->fromMonth,
            'placed' => $placed,
            'kind' => $mapping instanceof MappingView ? $mapping->kind : null,
            'terms' => $mapping instanceof MappingView ? array_map($this->term(...), $mapping->terms) : [],
        ];
    }

    /**
     * @return array{own: bool, name: ?string, percent: ?string, amount: ?string, rest: bool}
     */
    private function term(TermView $term): array
    {
        return [
            'own' => 'own' === $term->recipient,
            'name' => $term->recipientName,
            'percent' => $term->percent,
            'amount' => $term->amountCzk instanceof Money ? $this->text->shown($term->amountCzk) : null,
            'rest' => $term->rest,
        ];
    }
}
