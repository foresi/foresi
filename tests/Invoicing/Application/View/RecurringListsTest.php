<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Application\View;

use Foresi\Invoicing\Application\View\RecurringLists;
use Foresi\Shared\Application\View\DefaultView;
use Foresi\Shared\Application\View\FieldType;
use Foresi\Shared\Domain\View\Placement;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The recurring invoices' list definition (saved views D3, D12): its fields and the tabs it had, after All.
 */
final class RecurringListsTest extends KernelTestCase
{
    public function testItShipsTheTabsItHadInTheirOrderWithTheirCounts(): void
    {
        $list = $this->lists()->list();

        self::assertEquals([$list], iterator_to_array($this->lists()->lists()));
        self::assertSame('invoicing.recurring', $list->key);
        self::assertSame(
            [
                ['all', 'recurring.list.tab.all', [], 0],
                ['active', 'recurring.list.tab.active', [['field' => 'state', 'op' => 'is', 'value' => 'active']], 1],
                ['paused', 'recurring.list.tab.paused', [['field' => 'state', 'op' => 'is', 'value' => 'paused']], 2],
                ['ended', 'recurring.list.tab.ended', [['field' => 'state', 'op' => 'is', 'value' => 'ended']], 3],
                ['failing', 'recurring.list.tab.failing', [['field' => 'failing', 'op' => 'is', 'value' => true]], 4],
            ],
            array_map(static fn (DefaultView $view): array => [$view->slug, $view->name, $view->filter->toArray()['conditions'], $view->position], $list->defaults),
        );
        foreach ($list->defaults as $view) {
            self::assertTrue($view->showCount);
            self::assertSame(Placement::Primary, $view->placement);
        }
    }

    public function testItsFieldsNameTheirValues(): void
    {
        $fields = $this->lists()->list()->fields;

        self::assertSame(['state', 'failing', 'delivery', 'name', 'description', 'next'], array_keys($fields));
        self::assertSame([FieldType::Enum, FieldType::Boolean, FieldType::Enum, FieldType::Text, FieldType::Text, FieldType::Date], array_values(array_map(static fn (\Foresi\Shared\Application\View\Field $field): FieldType => $field->type, $fields)));
        self::assertSame(['active' => 'recurring.list.tab.active', 'paused' => 'recurring.list.tab.paused', 'ended' => 'recurring.list.tab.ended'], $fields['state']->optionLabels);
        self::assertSame(['send' => 'recurring.list.delivery.send', 'review' => 'recurring.list.delivery.review'], $fields['delivery']->optionLabels);
        self::assertSame(['next_occurrence'], $fields['next']->aliases);
        self::assertSame('r.nextOccurrence', $fields['next']->expression);
        self::assertSame(['recurring.list.column.state', 'recurring.list.tab.failing', 'recurring.list.column.delivery', 'recurring.list.column.name', 'recurring.list.column.description', 'recurring.list.column.next'], array_values(array_map(static fn (\Foresi\Shared\Application\View\Field $field): string => $field->label, $fields)));
    }

    public function testTheListIsBuiltOnce(): void
    {
        self::assertSame($this->lists()->list(), $this->lists()->list());
    }

    private function lists(): RecurringLists
    {
        return self::getContainer()->get(RecurringLists::class);
    }
}
