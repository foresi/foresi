<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Application\View;

use Foresi\Invoicing\Application\View\SendLogLists;
use Foresi\Shared\Application\View\FieldType;
use Foresi\Shared\Application\View\ListDefinition;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SendLogListsTest extends KernelTestCase
{
    public function testTheLogShipsItsTabsAsViewsWithoutCounts(): void
    {
        $lists = iterator_to_array($this->lists()->lists(), false);
        self::assertCount(1, $lists);
        $list = $lists[0];
        self::assertSame('invoicing.sends', $list->key);

        $defaults = [];
        foreach ($list->defaults as $default) {
            $defaults[$default->slug] = [$default->name, $default->filter->toArray()['conditions'], $default->showCount, $default->position];
        }
        self::assertSame([
            ListDefinition::ALL => ['invoice.sends.tab.all', [], false, 0],
            'sent' => ['invoice.sends.tab.sent', [['field' => 'status', 'op' => 'is', 'value' => 'sent']], false, 1],
            'queued' => ['invoice.sends.tab.queued', [['field' => 'status', 'op' => 'is', 'value' => 'queued']], false, 2],
            'failed' => ['invoice.sends.tab.failed', [['field' => 'status', 'op' => 'is', 'value' => 'failed']], false, 3],
        ], $defaults);
    }

    public function testTheFieldsAreTheStateAndTheType(): void
    {
        $fields = $this->lists()->list()->fields;

        self::assertSame(['status', 'purpose', 'number', 'when', 'customer'], array_keys($fields));
        self::assertSame(FieldType::Enum, $fields['status']->type);
        self::assertSame('invoice.sends.column.status', $fields['status']->label);
        self::assertSame(['queued', 'sent', 'failed', 'cancelled'], $fields['status']->options);
        self::assertSame(['queued' => 'invoice.sends.status.queued', 'sent' => 'invoice.sends.status.sent', 'failed' => 'invoice.sends.status.failed', 'cancelled' => 'invoice.sends.status.cancelled'], $fields['status']->optionLabels);
        self::assertSame(FieldType::Enum, $fields['purpose']->type);
        self::assertSame('reminder.log.column', $fields['purpose']->label);
        self::assertSame(['invoice', 'reminder'], $fields['purpose']->options);
        self::assertSame(['invoice' => 'reminder.log.purpose.invoice', 'reminder' => 'reminder.log.purpose.reminder'], $fields['purpose']->optionLabels);
    }

    public function testTheListIsBuiltOnce(): void
    {
        self::assertSame($this->lists()->list(), $this->lists()->list());
    }

    private function lists(): SendLogLists
    {
        return self::getContainer()->get(SendLogLists::class);
    }
}
