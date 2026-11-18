<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Ui\Column;

use Foresi\Ui\Column\ListColumnRegistry;
use Foresi\Ui\Column\ListDisplay;
use Foresi\Ui\Contract\Column;
use Foresi\Ui\Contract\ListColumns;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\IdentityTranslator;

final class ListColumnRegistryTest extends TestCase
{
    private const string LIST = 'invoicing.issued';

    public function testWithoutADisplayShowsTheDefaultColumnsInTheirDefaultOrder(): void
    {
        $display = $this->registry()->display(self::LIST);

        self::assertSame(['number', 'customer', 'dueDate', 'payable'], $display->keys());
        self::assertNull($display->density);
        self::assertNull($display->groupBy);
    }

    public function testAColumnTheContextMakesUnavailableIsLeftOut(): void
    {
        self::assertSame(['number', 'firm', 'customer', 'dueDate', 'payable'], $this->registry()->display(self::LIST, ['severalFirms' => true])->keys());
        self::assertSame(['number', 'customer', 'dueDate', 'payable'], $this->registry()->display(self::LIST, ['severalFirms' => false])->keys());
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
        $display = $this->registry()->display(self::LIST, [], ['columns' => [['key' => 'number', 'width' => 160], ['key' => 'customer', 'width' => 10], ['key' => 'payable', 'width' => 'wide'], ['key' => 'dueDate']]]);

        self::assertSame([160, 64, null, null], array_map(static fn ($column): ?int => $column->width, $display->columns));
    }

    public function testDefaultsKeepTheDefaultWidth(): void
    {
        $display = $this->registry()->display(self::LIST);

        self::assertSame([120, null, null, null], array_map(static fn ($column): ?int => $column->width, $display->columns));
    }

    public function testDensityAndGroupingAreReadWhenValid(): void
    {
        $registry = $this->registry();

        self::assertSame(ListDisplay::DENSE, $registry->display(self::LIST, [], ['columns' => [], 'density' => 'dense', 'group_by' => 'status'])->density);
        self::assertSame('status', $registry->display(self::LIST, [], ['columns' => [], 'group_by' => 'status'])->groupBy);
        self::assertNull($registry->display(self::LIST, [], ['columns' => [], 'density' => 'huge'])->density);
    }

    public function testADisplayWithoutColumnsKeepsTheDefaultColumns(): void
    {
        self::assertSame(['number', 'customer', 'dueDate', 'payable'], $this->registry()->display(self::LIST, [], ['density' => 'dense'])->keys());
    }

    public function testAnotherModuleAddsAColumnToTheList(): void
    {
        $project = new class implements ListColumns {
            public function columns(string $list): iterable
            {
                return 'invoicing.issued' === $list ? [new Column('project', 'work.column.project', static fn (array $row): string => \sprintf('P-%s', \is_string($row['number'] ?? null) ? $row['number'] : ''), defaultPosition: 35)] : [];
            }
        };
        $display = $this->registry($project)->display(self::LIST);

        self::assertSame(['number', 'customer', 'project', 'dueDate', 'payable'], $display->keys());
        self::assertSame('P-2026001', $display->cells(['number' => '2026001', 'customer' => 'Example Company'])['project']);
    }

    public function testCellsAreRenderedInTheListsContext(): void
    {
        $display = $this->registry()->display(self::LIST, ['severalFirms' => true]);

        self::assertSame(['number' => '2026001', 'firm' => 'firm of 2026001', 'customer' => 'Example Company', 'dueDate' => '', 'payable' => ''], $display->cells(['number' => '2026001', 'customer' => 'Example Company']));
    }

    public function testTheTableColumnsLinkTheSortableHeaders(): void
    {
        $table = $this->registry()->display(self::LIST)->table('-payable', static fn (string $sort): string => '/invoices?sort='.$sort);

        self::assertSame(['key' => 'number', 'label' => 'invoice.list.column.number', 'align' => 'left', 'phone' => true, 'width' => 120, 'sortable' => true, 'sort' => null, 'href' => '/invoices?sort=number'], $table[0]);
        self::assertSame(['key' => 'customer', 'label' => 'invoice.list.column.customer', 'align' => 'left', 'phone' => false, 'truncate' => true], $table[1]);
        self::assertSame(['sortable' => true, 'sort' => 'desc', 'href' => '/invoices?sort=payable'], array_intersect_key($table[3], ['sortable' => 0, 'sort' => 0, 'href' => 0]));
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

    public function testRefusesAKeyThatIsNotAnIdentifier(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Column('Due date', 'label', static fn (): string => '');
    }

    public function testRefusesADefaultWidthBelowTheMinimum(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Column('number', 'label', static fn (): string => '', defaultWidth: 40);
    }

    private function registry(ListColumns ...$more): ListColumnRegistry
    {
        return new ListColumnRegistry([$this->provider(), ...$more], new IdentityTranslator());
    }

    private function provider(): ListColumns
    {
        return new class implements ListColumns {
            public function columns(string $list): iterable
            {
                if ('invoicing.issued' !== $list) {
                    return [];
                }
                $text = static fn (string $key): \Closure => static fn (array $row): string => \is_string($row[$key] ?? null) ? $row[$key] : '';

                return [
                    new Column('payable', 'invoice.list.column.payable', $text('payable'), defaultPosition: 70, align: 'right', sortKey: 'payable'),
                    new Column('number', 'invoice.list.column.number', $text('number'), defaultPosition: 10, defaultWidth: 120, sortKey: 'number', locked: true),
                    new Column('firm', 'invoice.list.column.firm', static fn (array $row): string => \sprintf('firm of %s', \is_string($row['number'] ?? null) ? $row['number'] : ''), defaultPosition: 20, available: static fn (array $context): bool => true === ($context['severalFirms'] ?? false)),
                    new Column('customer', 'invoice.list.column.customer', $text('customer'), defaultPosition: 30, phone: false, truncate: true),
                    new Column('note', 'invoice.list.column.note', $text('note'), defaultVisible: false, defaultPosition: 60),
                    new Column('dueDate', 'invoice.list.column.dueDate', $text('dueDate'), defaultPosition: 50, sortKey: 'dueDate'),
                ];
            }
        };
    }
}
