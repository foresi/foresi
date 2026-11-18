<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Application\View;

use Foresi\Shared\Application\View\DefaultView;
use Foresi\Shared\Application\View\Field;
use Foresi\Shared\Application\View\FieldType;
use Foresi\Shared\Domain\View\Placement;
use Foresi\Work\Application\View\MyTaskLists;
use PHPUnit\Framework\TestCase;

/**
 * My tasks' list definition (saved views D3, D12): its fields and the tabs it had, none counted (D9).
 */
final class MyTaskListsTest extends TestCase
{
    public function testItShipsAllAndTheTabsItHadInTheirOrderWithoutCounts(): void
    {
        $list = MyTaskLists::list();

        self::assertEquals([$list], iterator_to_array(new MyTaskLists()->lists()));
        self::assertSame('work.my_tasks', $list->key);
        self::assertSame(
            [
                ['all', 'work.my_tasks.tab.all', [], 0],
                ['assigned', 'work.my_tasks.tab.assigned', [['field' => 'involvement', 'op' => 'is', 'value' => 'responsible']], 1],
                ['watching', 'work.my_tasks.tab.watching', [['field' => 'involvement', 'op' => 'is', 'value' => 'watcher']], 2],
            ],
            array_map(static fn (DefaultView $view): array => [$view->slug, $view->name, $view->filter->toArray()['conditions'], $view->position], $list->defaults),
        );
        foreach ($list->defaults as $view) {
            self::assertFalse($view->showCount);
            self::assertSame(Placement::Primary, $view->placement);
        }
    }

    public function testItsFieldsAreHowTheTaskIsMineAndTheClient(): void
    {
        $fields = MyTaskLists::list()->fields;

        self::assertSame(['involvement' => 'work.my_tasks.involvement', 'client' => 'work.my_tasks.client'], array_map(static fn (Field $field): string => $field->label, $fields));
        self::assertSame(FieldType::Enum, $fields['involvement']->type);
        self::assertSame(['responsible' => 'work.my_tasks.involvement_option.responsible', 'watcher' => 'work.my_tasks.involvement_option.watcher'], $fields['involvement']->optionLabels);
        self::assertSame(['responsible', 'watcher'], $fields['involvement']->options);
        self::assertSame(FieldType::Reference, $fields['client']->type);
        self::assertSame('contact', $fields['client']->source);
    }
}
