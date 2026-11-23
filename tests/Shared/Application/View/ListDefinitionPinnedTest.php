<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Application\View;

use Foresi\Shared\Application\View\DefaultView;
use Foresi\Shared\Application\View\Field;
use Foresi\Shared\Application\View\FieldType;
use Foresi\Shared\Application\View\ListDefinition;
use PHPUnit\Framework\TestCase;

/**
 * The quick filters a list pins by default, which every view that keeps none of its own shows.
 */
final class ListDefinitionPinnedTest extends TestCase
{
    public function testAListPinsItsDefaultQuickFiltersUnderTheirKeys(): void
    {
        $list = $this->list(['customer', 'due']);

        self::assertSame(['customer', 'due_date'], $list->pinned, 'An alias names its field.');
        self::assertSame([], $this->list()->pinned, 'A list may pin none.');
    }

    public function testAPinnedFieldIsOneOfTheList(): void
    {
        $this->expectExceptionMessageIsOrContains('pins "colour", which is none of its fields');

        $this->list(['colour']);
    }

    public function testAListPinsAtMostFour(): void
    {
        $this->expectExceptionMessageIsOrContains('pins at most 4 quick filters');

        $this->list(['customer', 'due_date', 'paid', 'number', 'customer']);
    }

    public function testAStoredListIsReadAsTheListStillHasItAndNoneStoredIsTheListsOwn(): void
    {
        $list = $this->list(['customer']);

        self::assertSame(['customer'], $list->pinnedOf(null));
        self::assertSame(['customer'], $list->pinnedOf('not a list'));
        self::assertSame([], $list->pinnedOf([]));
        self::assertSame(['due_date', 'paid'], $list->pinnedOf(['due', 'gone', 'paid', 'due_date', 7]));
        self::assertSame(['customer', 'due_date', 'paid', 'number'], $list->pinnedOf(['customer', 'due_date', 'paid', 'number', 'customer']));
    }

    /**
     * @param list<string> $pinned
     */
    private function list(array $pinned = []): ListDefinition
    {
        return new ListDefinition('test.items', [
            new Field('customer', FieldType::Text, 'Customer'),
            new Field('due_date', FieldType::Date, 'Due date', aliases: ['due']),
            new Field('paid', FieldType::Boolean, 'Paid'),
            new Field('number', FieldType::Text, 'Number'),
        ], [new DefaultView('all', 'All')], $pinned);
    }
}
