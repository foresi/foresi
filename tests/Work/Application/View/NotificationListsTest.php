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
use Foresi\Work\Application\View\NotificationLists;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The inbox's list definition (saved views D3, D12): its fields and the two tabs it had, All
 * (what is not done) and Done, neither counted (D9).
 */
final class NotificationListsTest extends KernelTestCase
{
    public function testItShipsTheTabsItHadWithoutCounts(): void
    {
        $list = $this->lists()->list();

        self::assertEquals([$list], iterator_to_array($this->lists()->lists()));
        self::assertSame('work.notifications', $list->key);
        self::assertSame(
            [
                ['all', 'work.notifications.tab.all', [], 0],
                ['done', 'work.notifications.tab.done', [['field' => 'done', 'op' => 'is', 'value' => true]], 1],
            ],
            array_map(static fn (DefaultView $view): array => [$view->slug, $view->name, $view->filter->toArray()['conditions'], $view->position], $list->defaults),
        );
        foreach ($list->defaults as $view) {
            self::assertFalse($view->showCount);
            self::assertSame(Placement::Primary, $view->placement);
        }
    }

    public function testItsFieldsNameTheirValues(): void
    {
        $fields = $this->lists()->list()->fields;

        self::assertSame(['reason', 'unread', 'done', 'task', 'project', 'at'], array_keys($fields));
        self::assertSame([FieldType::Enum, FieldType::Boolean, FieldType::Boolean, FieldType::Text, FieldType::Text, FieldType::Date], array_values(array_map(static fn (Field $field): FieldType => $field->type, $fields)));
        self::assertSame(['mentions' => 'work.notifications.filter.mentions', 'assigned' => 'work.notifications.filter.assigned'], $fields['reason']->optionLabels);
        self::assertSame(['work.notifications.field.reason', 'work.notifications.field.unread', 'work.notifications.tab.done', 'work.notifications.column.task', 'work.task.field.project', 'work.notifications.column.at'], array_values(array_map(static fn (Field $field): string => $field->label, $fields)));
        self::assertSame('n.createdAt', $fields['at']->expression);
    }

    public function testTheListIsBuiltOnce(): void
    {
        self::assertSame($this->lists()->list(), $this->lists()->list());
    }

    private function lists(): NotificationLists
    {
        return self::getContainer()->get(NotificationLists::class);
    }
}
