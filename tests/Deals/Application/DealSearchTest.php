<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Deals\Application;

use Foresi\Deals\Application\View\DealLists;
use Foresi\Deals\Application\View\DealQueries;
use Foresi\Deals\Domain\Deal;
use Foresi\Party\Application\PartyQuery;
use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\FieldType;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Tests\Deals\Support\SalesTestCase;

/**
 * The text search and the stage filter of the deal list: the title and the client's
 * name, whatever the case, and the stage as a condition of the filter sheet and its chip.
 */
final class DealSearchTest extends SalesTestCase
{
    public function testTheListIsFoundByTheTitleOrTheNameOfTheClient(): void
    {
        $beta = $this->company('Beta Example a.s.');
        $this->deal('Roof audit');
        $this->deal('Gutter audit', $beta);
        $queries = self::getContainer()->get(DealQueries::class);
        $parties = self::getContainer()->get(PartyQuery::class);

        $found = fn (string $text): array => array_map(static fn (Deal $deal): string => $deal->title(), $queries->index(new ViewFilter(), false, $text, '' === $text ? [] : $parties->matchingIds($text, null, false)));
        self::assertSame(['Gutter audit', 'Roof audit'], $found(''));
        self::assertSame(['Roof audit'], $found('ROOF'));
        self::assertSame(['Gutter audit'], $found('beta example'), 'by the name of the client');
        self::assertSame([], $found('%'));
        self::assertSame([], $found('_oof'));
        // Title and client are asked together with the filter and the order.
        self::assertSame(['Roof audit', 'Gutter audit'], array_map(static fn (Deal $deal): string => $deal->title(), $queries->index(new ViewFilter(), true, 'AUDIT')));
    }

    public function testTheStageFilterTakesIsIsNotAndIsAnyOf(): void
    {
        $this->deal('Roof audit');
        $this->deal('Gutter audit', stageId: $this->stages['proposal']->id());
        $queries = self::getContainer()->get(DealQueries::class);
        $proposal = $this->stages['proposal']->id();
        $inquiry = $this->stages['inquiry']->id();

        self::assertSame(['Gutter audit'], $this->titles($queries->index(ViewFilter::all(new Condition(DealLists::STAGE, 'is', $proposal)))));
        self::assertSame(['Roof audit'], $this->titles($queries->index(ViewFilter::all(new Condition(DealLists::STAGE, 'is_not', $proposal)))));
        self::assertSame(['Gutter audit', 'Roof audit'], $this->titles($queries->index(ViewFilter::all(new Condition(DealLists::STAGE, 'is_any_of', [$proposal, $inquiry])))));
    }

    public function testTheStageIsALookupOfTheStagesAndTheFirstQuickFilter(): void
    {
        $list = self::getContainer()->get(DealLists::class)->list();
        $stage = $list->field(DealLists::STAGE);

        self::assertSame([FieldType::Reference, DealLists::STAGE_SOURCE, ['is', 'is_not', 'is_any_of'], 'deals.deals.field.stage'], [$stage?->type, $stage?->source, $stage?->operators, $stage?->label]);
        self::assertSame([DealLists::STAGE, DealLists::OWNER], $list->pinned);
    }

    /**
     * @param list<Deal> $deals
     *
     * @return list<string>
     */
    private function titles(array $deals): array
    {
        return array_map(static fn (Deal $deal): string => $deal->title(), $deals);
    }
}
