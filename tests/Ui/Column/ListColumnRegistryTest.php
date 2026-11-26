<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Ui\Column;

use Foresi\Shared\Domain\View\AttributeType;
use Foresi\Tests\Ui\Support\TestColumns;
use Foresi\Ui\Column\ListColumnRegistry;
use Foresi\Ui\Contract\Attribute;
use Foresi\Ui\Contract\Column;
use Foresi\Ui\Contract\DataSource;
use Foresi\Ui\Contract\ListDisplay;
use Foresi\Ui\Contract\ResolvedColumn;
use PHPUnit\Framework\TestCase;

final class ListColumnRegistryTest extends TestCase
{
    private const string LIST = 'invoicing.issued';

    public function testWithoutADisplayShowsTheDefaultColumnsInTheirDefaultOrder(): void
    {
        $display = $this->registry()->display(self::LIST);

        self::assertSame(['number', 'customer', 'due_date', 'payable'], $display->keys());
        self::assertNull($display->density);
        self::assertNull($display->groupBy);
    }

    public function testAColumnTheContextMakesUnavailableIsLeftOut(): void
    {
        self::assertSame(['number', 'firm', 'customer', 'due_date', 'payable'], $this->registry()->display(self::LIST, ['severalFirms' => true])->keys());
        self::assertSame(['number', 'customer', 'due_date', 'payable'], $this->registry()->display(self::LIST, ['severalFirms' => false])->keys());
    }

    public function testTheDisplayOrdersTheColumnsAndPresenceMeansVisible(): void
    {
        $display = $this->registry()->display(self::LIST, [], ['columns' => [['key' => 'payable'], ['key' => 'number'], ['key' => 'note']]]);

        self::assertSame(['payable', 'number', 'note'], $display->keys());
    }

    public function testUnknownRepeatedAndUnavailableKeysAreDropped(): void
    {
        $display = $this->registry()->display(self::LIST, ['severalFirms' => false], ['columns' => [['key' => 'number'], ['key' => 'gone'], ['key' => 'firm'], ['key' => 'number'], 'payable', ['width' => 80], 7]]);

        self::assertSame(['number', 'payable'], $display->keys());
    }

    public function testALockedColumnLeftOutComesBackFirst(): void
    {
        $display = $this->registry()->display(self::LIST, [], ['columns' => [['key' => 'payable'], ['key' => 'customer']]]);

        self::assertSame(['number', 'payable', 'customer'], $display->keys());
    }

    public function testWidthsComeFromTheDisplayAndNeverGoBelowTheMinimum(): void
    {
        $display = $this->registry()->display(self::LIST, [], ['columns' => [['key' => 'number', 'width' => 160], ['key' => 'customer', 'width' => 10], ['key' => 'payable', 'width' => 'wide'], ['key' => 'due_date']]]);

        self::assertSame([160, 64, null, null], array_map(static fn (ResolvedColumn $column): ?int => $column->width, $display->columns));
    }

    public function testAWidthOfZeroOrLessSizesTheColumnToItsContent(): void
    {
        $display = $this->registry()->display(self::LIST, [], ['columns' => [['key' => 'number', 'width' => 0], ['key' => 'customer', 'width' => -5]]]);

        self::assertSame([null, null], array_map(static fn (ResolvedColumn $column): ?int => $column->width, $display->columns));
    }

    public function testDefaultsKeepTheDefaultWidth(): void
    {
        $display = $this->registry()->display(self::LIST);

        self::assertSame([120, null, null, null], array_map(static fn (ResolvedColumn $column): ?int => $column->width, $display->columns));
    }

    public function testDensityAndGroupingAreReadWhenValid(): void
    {
        $registry = $this->registry();

        self::assertSame(ListDisplay::DENSE, $registry->display(self::LIST, [], ['columns' => [], 'density' => 'dense', 'group_by' => 'status'])->density);
        self::assertSame('status', $registry->display(self::LIST, [], ['columns' => [], 'group_by' => 'status'])->groupBy);
        self::assertNull($registry->display(self::LIST, [], ['columns' => [], 'density' => 'huge'])->density);
        self::assertNull($registry->display(self::LIST, [], ['columns' => [], 'group_by' => ''])->groupBy);
        self::assertNull($registry->display(self::LIST, [], ['columns' => [], 'group_by' => 5])->groupBy);
    }

    public function testADisplayWithoutColumnsKeepsTheDefaultColumns(): void
    {
        self::assertSame(['number', 'customer', 'due_date', 'payable'], $this->registry()->display(self::LIST, [], ['density' => 'dense'])->keys());
    }

    public function testAnotherModuleAddsAColumnToTheList(): void
    {
        $project = new class implements DataSource {
            public function attributes(string $list): iterable
            {
                return 'invoicing.issued' === $list ? [new Attribute('project', 'work.column.project', AttributeType::Text, static fn (array $row): string => \sprintf('P-%s', \is_string($row['number'] ?? null) ? $row['number'] : ''), defaultVisible: true, defaultPosition: 35)] : [];
            }
        };
        $display = $this->registry($project)->display(self::LIST);

        self::assertSame(['number', 'customer', 'project', 'due_date', 'payable'], $display->keys());
        self::assertSame('P-2026001', $display->cells(['number' => '2026001', 'customer' => 'Example Company'])['project']);
    }

    public function testCellsAreRenderedInTheListsContext(): void
    {
        $display = $this->registry()->display(self::LIST, ['severalFirms' => true]);

        self::assertSame(['number' => '2026001', 'firm' => 'firm of 2026001', 'customer' => 'Example Company', 'due_date' => null, 'payable' => null], $display->cells(['number' => '2026001', 'customer' => 'Example Company']));
    }

    public function testTheTableColumnsLinkTheSortableHeaders(): void
    {
        $table = $this->registry()->display(self::LIST)->table('-payable', static fn (string $sort): string => '/invoices?sort='.$sort);

        self::assertSame(['key' => 'number', 'label' => 'invoice.list.column.number', 'align' => 'left', 'phone' => true, 'minWidth' => 64, 'width' => 120, 'sortable' => true, 'sort' => null, 'href' => '/invoices?sort=number'], $table[0]);
        self::assertSame(['key' => 'customer', 'label' => 'invoice.list.column.customer', 'align' => 'left', 'phone' => false, 'minWidth' => 64, 'truncate' => true], $table[1]);
        self::assertSame(['sortable' => true, 'sort' => 'desc', 'href' => '/invoices?sort=payable'], array_intersect_key($table[3], ['sortable' => 0, 'sort' => 0, 'href' => 0]));
    }

    public function testTheHeaderOfTheSortedColumnLinksToTheReverseOrder(): void
    {
        $table = $this->registry()->display(self::LIST)->table('number', static fn (string $sort): string => '/invoices?sort='.$sort);

        self::assertSame(['sortable' => true, 'sort' => 'asc', 'href' => '/invoices?sort=-number'], array_intersect_key($table[0], ['sortable' => 0, 'sort' => 0, 'href' => 0]));
    }

    public function testAWideColumnSaysSoToTheTable(): void
    {
        $wide = new class implements DataSource {
            public function attributes(string $list): iterable
            {
                return [new Attribute('issue_date', 'invoice.list.column.issue_date', AttributeType::Text, static fn (): string => '', defaultVisible: true, defaultPosition: 45, wide: true)];
            }
        };
        $table = $this->registry($wide)->display(self::LIST)->table();

        self::assertTrue($table[2]['wide']);
        self::assertArrayNotHasKey('wide', $table[0]);
    }

    public function testAColumnWithoutAPositionComesAtZero(): void
    {
        $columns = new class implements DataSource {
            public function attributes(string $list): iterable
            {
                return [
                    new Attribute('later', 'later', AttributeType::Text, static fn (): string => '', defaultVisible: true, defaultPosition: 1),
                    new Attribute('unplaced', 'unplaced', AttributeType::Text, static fn (): string => '', defaultVisible: true),
                    new Attribute('earlier', 'earlier', AttributeType::Text, static fn (): string => '', defaultVisible: true, defaultPosition: -1),
                ];
            }
        };

        self::assertSame(['earlier', 'unplaced', 'later'], array_map(static fn (Column $column): string => $column->key, TestColumns::registry($columns)->columns('any')));
    }

    public function testAColumnMayBeAsNarrowAsItsMinimum(): void
    {
        $column = new Attribute('flag', 'label', AttributeType::Text, static fn (): string => '', defaultVisible: true, defaultWidth: 1, minWidth: 1);

        self::assertSame(1, $column->defaultWidth);
        self::assertSame(64, new Attribute('note', 'label', AttributeType::Text, static fn (): string => '', defaultVisible: true, defaultWidth: 64)->defaultWidth);
    }

    public function testRefusesAKeyWithCharactersAfterAnIdentifier(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Attribute('dueDate!', 'label', AttributeType::Text, static fn (): string => '', defaultVisible: true);
    }

    public function testTwoColumnsWithOneKeyAreRefused(): void
    {
        $this->expectException(\LogicException::class);

        $this->registry($this->provider())->columns(self::LIST);
    }

    public function testAnotherListIsEmpty(): void
    {
        self::assertSame([], $this->registry()->display('party.contacts')->keys());
    }

    public function testAnAliasInADisplayNamesItsColumnUnderItsKey(): void
    {
        $display = $this->registry()->display(self::LIST, [], ['columns' => [['key' => 'dueDate', 'width' => 90], 'due_date']]);

        self::assertSame(['number', 'due_date'], $display->keys());
        self::assertSame(90, $display->columns[1]->width);
    }

    public function testAKeyOrAnAliasNamesAColumn(): void
    {
        $registry = $this->registry();

        self::assertSame('due_date', $registry->key(self::LIST, 'dueDate'));
        self::assertSame('due_date', $registry->key(self::LIST, 'due_date'));
        self::assertNull($registry->key(self::LIST, 'gone'));
    }

    public function testASortNamesASortableColumnByItsKeyOrAlias(): void
    {
        $registry = $this->registry();

        self::assertSame('-due_date', $registry->sort(self::LIST, '-dueDate', '-number'));
        self::assertSame('due_date', $registry->sort(self::LIST, 'due_date', '-number'));
        self::assertSame('payable', $registry->sort(self::LIST, 'payable', '-number'));
        // The customer is not sortable here, and nothing else is.
        self::assertSame('-number', $registry->sort(self::LIST, 'customer', '-number'));
        self::assertSame('-number', $registry->sort(self::LIST, 'nonsense', '-number'));
        self::assertSame('-number', $registry->sort(self::LIST, '--due_date', '-number'));
    }

    public function testTheColumnAlignsByTheTypeOfItsAttribute(): void
    {
        $columns = $this->registry()->columns(self::LIST);

        self::assertSame(['left', 'left', 'left', 'left', 'left', 'right'], array_map(static fn (Column $column): string => $column->align, $columns));
    }

    public function testAnAttributeWithACellOfItsOwnRendersIt(): void
    {
        $own = new class implements DataSource {
            public function attributes(string $list): iterable
            {
                return [new Attribute('state', 'state', AttributeType::Enum, static fn (): string => 'paid', defaultVisible: true, options: ['paid' => 'state.paid'], cell: static fn (): array => ['text' => 'Paid', 'tone' => 'green'])];
            }
        };

        self::assertSame(['text' => 'Paid', 'tone' => 'green'], TestColumns::registry($own)->display('any')->cells([])['state']);
    }

    public function testAnAliasThatNamesAnotherAttributeIsRefused(): void
    {
        $clash = new class implements DataSource {
            public function attributes(string $list): iterable
            {
                return [new Attribute('payment', 'payment', AttributeType::Text, static fn (): string => '', aliases: ['payable'])];
            }
        };

        $this->expectException(\LogicException::class);

        $this->registry($clash)->columns(self::LIST);
    }

    public function testRefusesAKeyInCamelCase(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Attribute('dueDate', 'label', AttributeType::Date, static fn (): string => '');
    }

    public function testRefusesAnEnumWithoutItsValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Attribute('state', 'label', AttributeType::Enum, static fn (): string => '');
    }

    public function testRefusesValuesOnAnythingButAnEnum(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Attribute('state', 'label', AttributeType::Text, static fn (): string => '', options: ['a' => 'b']);
    }

    public function testAnAttributeIsHiddenAndUnsortableUnlessItSaysSo(): void
    {
        $attribute = new Attribute('note', 'label', AttributeType::Text, static fn (): string => '');

        self::assertFalse($attribute->defaultVisible);
        self::assertFalse($attribute->sortable());
        self::assertFalse($attribute->hasCell());
        self::assertNull($attribute->cell([]));
    }

    public function testRefusesAKeyThatIsNotAnIdentifier(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Attribute('Due date', 'label', AttributeType::Text, static fn (): string => '', defaultVisible: true);
    }

    public function testRefusesAnAliasThatIsTheKeyItself(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Attribute('number', 'label', AttributeType::Text, static fn (): string => '', aliases: ['number']);
    }

    public function testRefusesAnAliasThatIsNoOlderKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Attribute('due_date', 'label', AttributeType::Text, static fn (): string => '', aliases: ['Due date']);
    }

    public function testEachListIsReadFromItsSourcesOnce(): void
    {
        $counting = new class implements DataSource {
            public int $reads = 0;

            public function attributes(string $list): iterable
            {
                ++$this->reads;

                return [];
            }
        };
        $registry = $this->registry($counting);

        $registry->columns(self::LIST);
        $registry->display(self::LIST);

        self::assertSame(1, $counting->reads);
    }

    public function testRefusesADefaultWidthBelowTheMinimum(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Attribute('number', 'label', AttributeType::Text, static fn (): string => '', defaultVisible: true, defaultWidth: 40);
    }

    private function registry(DataSource ...$more): ListColumnRegistry
    {
        return TestColumns::registry($this->provider(), ...$more);
    }

    private function provider(): DataSource
    {
        return new class implements DataSource {
            public function attributes(string $list): iterable
            {
                if ('invoicing.issued' !== $list) {
                    return [];
                }
                $text = static fn (string $key): \Closure => static fn (array $row): string => \is_string($row[$key] ?? null) ? $row[$key] : '';

                return [
                    new Attribute('payable', 'invoice.list.column.payable', AttributeType::Number, $text('payable'), sort: 'e.payable', defaultVisible: true, defaultPosition: 70),
                    new Attribute('number', 'invoice.list.column.number', AttributeType::Text, $text('number'), sort: 'e.number', defaultVisible: true, defaultPosition: 10, defaultWidth: 120, locked: true),
                    new Attribute('firm', 'invoice.list.column.firm', AttributeType::Text, static fn (array $row): string => \sprintf('firm of %s', \is_string($row['number'] ?? null) ? $row['number'] : ''), defaultVisible: true, defaultPosition: 20, available: static fn (array $context): bool => true === ($context['severalFirms'] ?? false)),
                    new Attribute('customer', 'invoice.list.column.customer', AttributeType::Text, $text('customer'), defaultVisible: true, defaultPosition: 30, phone: false, truncate: true),
                    new Attribute('note', 'invoice.list.column.note', AttributeType::Text, $text('note'), defaultPosition: 60),
                    new Attribute('due_date', 'invoice.list.column.due_date', AttributeType::Text, $text('due_date'), sort: 'e.dueDate', defaultVisible: true, defaultPosition: 50, aliases: ['dueDate']),
                ];
            }
        };
    }
}
