<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Application\View;

use Foresi\Shared\Application\View\InvalidFilter;
use Foresi\Shared\Application\View\ViewRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ViewRulesTest extends TestCase
{
    public function testASortKeepsItsFieldsInOrderAndDefaultsToAscending(): void
    {
        self::assertSame([], ViewRules::sort([]));
        self::assertSame(
            [['field' => 'due_date', 'dir' => 'asc'], ['field' => 'number', 'dir' => 'desc'], ['field' => 'total.net', 'dir' => 'asc']],
            ViewRules::sort([['field' => 'due_date'], ['field' => 'number', 'dir' => 'desc'], ['field' => 'total.net', 'dir' => 'asc']]),
        );
    }

    public function testASortHasAtMostThreeFields(): void
    {
        $item = ['field' => 'number'];
        self::assertCount(3, ViewRules::sort([$item, $item, $item]));

        $this->assertInvalid('sort', static fn (): array => ViewRules::sort([$item, $item, $item, $item]));
    }

    /**
     * @return iterable<string, array{list<mixed>, string}>
     */
    public static function badSorts(): iterable
    {
        yield 'not an object' => [['number'], 'sort[0]'];
        yield 'another key' => [[['field' => 'number', 'by' => 'asc']], 'sort[0]'];
        yield 'no field' => [[['dir' => 'asc']], 'sort[0].field'];
        yield 'field not a string' => [[['field' => 5]], 'sort[0].field'];
        yield 'field not a key' => [[['field' => 'Due date']], 'sort[0].field'];
        yield 'direction unknown' => [[['field' => 'number'], ['field' => 'number', 'dir' => 'up']], 'sort[1].dir'];
    }

    /**
     * @param list<mixed> $sort
     */
    #[DataProvider('badSorts')]
    public function testABadSortNamesWhatIsWrong(array $sort, string $path): void
    {
        $this->assertInvalid($path, static fn (): array => ViewRules::sort($sort));
    }

    public function testNoColumnsAndNoDensityIsTheListsDefault(): void
    {
        self::assertNull(ViewRules::display(null, null));
    }

    public function testADensityAloneIsAllThatIsStored(): void
    {
        self::assertSame(['density' => 'dense'], ViewRules::display(null, 'dense'));
        self::assertSame(['density' => 'comfortable'], ViewRules::display(null, 'comfortable'));
    }

    public function testColumnsAreKeysOrKeysWithAWidth(): void
    {
        self::assertSame(['columns' => []], ViewRules::display([], null));
        self::assertSame(
            ['columns' => [['key' => 'number'], ['key' => 'customer', 'width' => 240], ['key' => 'total']], 'density' => 'dense'],
            ViewRules::display(['number', ['key' => 'customer', 'width' => 240], ['key' => 'total', 'width' => null]], 'dense'),
        );
    }

    public function testAWidthIsFromOneToTwoThousandPixels(): void
    {
        self::assertSame(['columns' => [['key' => 'a', 'width' => 1]]], ViewRules::display([['key' => 'a', 'width' => 1]], null));
        self::assertSame(['columns' => [['key' => 'a', 'width' => 2000]]], ViewRules::display([['key' => 'a', 'width' => 2000]], null));

        foreach ([0, -4, 2001, '240', 24.5] as $width) {
            $this->assertInvalid('columns[0].width', static fn (): ?array => ViewRules::display([['key' => 'a', 'width' => $width]], null));
        }
    }

    public function testAViewShowsAtMostFortyColumns(): void
    {
        $display = ViewRules::display(array_fill(0, 40, 'number'), null);
        self::assertIsArray($display);
        self::assertIsArray($display['columns']);
        self::assertCount(40, $display['columns']);

        $this->assertInvalid('columns', static fn (): ?array => ViewRules::display(array_fill(0, 41, 'number'), null));
    }

    /**
     * @return iterable<string, array{list<mixed>, string}>
     */
    public static function badColumns(): iterable
    {
        yield 'a number' => [[5], 'columns[0]'];
        yield 'another key' => [[['key' => 'a', 'label' => 'A']], 'columns[0]'];
        yield 'no key' => [[['width' => 20]], 'columns[0].key'];
        yield 'key not a string' => [[['key' => 5]], 'columns[0].key'];
        yield 'key not a key' => [['number', 'Due date'], 'columns[1].key'];
    }

    /**
     * @param list<mixed> $columns
     */
    #[DataProvider('badColumns')]
    public function testABadColumnNamesWhatIsWrong(array $columns, string $path): void
    {
        $this->assertInvalid($path, static fn (): ?array => ViewRules::display($columns, null));
    }

    public function testAGroupingIsStoredByItsKey(): void
    {
        self::assertSame(['group_by' => 'person'], ViewRules::display(null, null, 'person'));
        self::assertSame(['density' => 'dense', 'group_by' => 'person'], ViewRules::display(null, 'dense', 'person'));
        self::assertSame(['columns' => [['key' => 'number']], 'group_by' => 'task'], ViewRules::display(['number'], null, 'task'));
        $this->assertInvalid('group_by', static fn (): ?array => ViewRules::display(null, null, 'Not a key'));
    }

    public function testTheQuickFiltersAreStoredOnceEachInTheirOrderAndAtMostFour(): void
    {
        self::assertSame(['pinned' => []], ViewRules::display(null, null, pinned: []), 'None pins none.');
        self::assertSame(['pinned' => ['due_date', 'paid']], ViewRules::display(null, null, pinned: ['due_date', 'due_date', 'paid']));
        self::assertSame(['pinned' => ['a', 'b', 'c', 'd']], ViewRules::display(null, null, pinned: ['a', 'b', 'c', 'd']));
        self::assertNull(ViewRules::display(null, null), 'Null keeps the list\'s own.');
        $this->assertInvalid('pinned', static fn (): ?array => ViewRules::display(null, null, pinned: ['a', 'b', 'c', 'd', 'e']));
    }

    public function testAQuickFilterIsNamedByTheKeyOfItsField(): void
    {
        $this->assertInvalid('pinned[1]', static fn (): ?array => ViewRules::display(null, null, pinned: ['paid', 'Not a key']));
        $this->assertInvalid('pinned[0]', static fn (): ?array => ViewRules::display(null, null, pinned: [7]));
    }

    public function testAnUnknownDensityIsRefused(): void
    {
        $this->assertInvalid('density', static fn (): ?array => ViewRules::display(['number'], 'airy'));
        $this->assertInvalid('density', static fn (): ?array => ViewRules::display(null, 'airy'));
    }

    private function assertInvalid(string $path, \Closure $call): void
    {
        try {
            $call();
            self::fail('It was accepted.');
        } catch (InvalidFilter $invalid) {
            self::assertSame($path, $invalid->path);
        }
    }
}
