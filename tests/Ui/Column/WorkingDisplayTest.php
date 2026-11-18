<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Ui\Column;

use Foresi\Ui\Column\ListColumnRegistry;
use Foresi\Ui\Column\ResolvedColumn;
use Foresi\Ui\Column\WorkingDisplay;
use Foresi\Ui\Contract\Column;
use Foresi\Ui\Contract\ListColumns;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\IdentityTranslator;

final class WorkingDisplayTest extends TestCase
{
    private const string LIST = 'party.contacts';

    public function testWithoutAWorkingStateTheListShowsItsViewUnchanged(): void
    {
        $state = $this->working()->read(self::LIST, [], null, []);

        self::assertSame(['name', 'kind', 'email'], $state->display->keys());
        self::assertFalse($state->modified);
        self::assertSame([], $state->query);
        self::assertNull($state->payload);
        self::assertSame('comfortable', $state->density);
        self::assertSame('list', $state->tableDensity());
    }

    public function testTheViewsStoredDisplayIsTheBase(): void
    {
        $saved = ['columns' => [['key' => 'name'], ['key' => 'email', 'width' => 200]], 'density' => 'dense'];
        $state = $this->working()->read(self::LIST, [], $saved, []);

        self::assertSame(['name', 'email'], $state->display->keys());
        self::assertSame('dense', $state->density);
        self::assertSame('dense', $state->tableDensity());
        self::assertFalse($state->modified);
        self::assertSame($saved, $state->payload);
    }

    public function testColumnsFromTheAddressOrderAndShowTheList(): void
    {
        $state = $this->working()->read(self::LIST, [], null, ['cols' => 'name,phone,kind']);

        self::assertSame(['name', 'phone', 'kind'], $state->display->keys());
        self::assertTrue($state->modified);
        self::assertSame(['cols' => 'name,phone,kind'], $state->query);
    }

    public function testAFormWithoutJavaScriptSendsTheColumnsAsAList(): void
    {
        $state = $this->working()->read(self::LIST, [], null, ['cols' => ['name', 'email']]);

        self::assertSame(['name', 'email'], $state->display->keys());
        self::assertSame(['cols' => 'name,email'], $state->query);
    }

    public function testUnknownKeysAreDroppedAndTheLockedColumnComesBack(): void
    {
        $state = $this->working()->read(self::LIST, [], null, ['cols' => 'gone,email,,email']);

        self::assertSame(['name', 'email'], $state->display->keys());
        self::assertSame(['cols' => 'name,email'], $state->query);
    }

    public function testAWorkingStateThatShowsTheViewIsNoChangeAndLeavesTheAddress(): void
    {
        $state = $this->working()->read(self::LIST, [], null, ['cols' => 'name,kind,email', 'density' => 'comfortable']);

        self::assertFalse($state->modified);
        self::assertSame([], $state->query);
    }

    public function testDensityAloneIsAChange(): void
    {
        $state = $this->working()->read(self::LIST, [], null, ['density' => 'dense']);

        self::assertSame(['name', 'kind', 'email'], $state->display->keys());
        self::assertTrue($state->modified);
        self::assertSame(['density' => 'dense'], $state->query);
        self::assertSame('dense', $state->tableDensity());
    }

    public function testAnUnknownDensityIsIgnored(): void
    {
        $state = $this->working()->read(self::LIST, [], null, ['density' => 'huge']);

        self::assertFalse($state->modified);
        self::assertSame('comfortable', $state->density);
    }

    public function testTheListsOwnDefaultDensityCounts(): void
    {
        $state = $this->working()->read(self::LIST, [], null, ['density' => 'dense'], 'dense');

        self::assertFalse($state->modified);
        self::assertSame('dense', $state->density);
    }

    public function testAKeptColumnKeepsTheWidthTheViewGivesIt(): void
    {
        $saved = ['columns' => [['key' => 'name', 'width' => 240], ['key' => 'email', 'width' => 180]]];
        $state = $this->working()->read(self::LIST, [], $saved, ['cols' => 'name,kind,email']);

        self::assertSame([240, null, 180], array_map(static fn (ResolvedColumn $column): ?int => $column->width, $state->display->columns));
        self::assertSame(['columns' => [['key' => 'name', 'width' => 240], ['key' => 'kind'], ['key' => 'email', 'width' => 180]], 'density' => 'comfortable'], $state->payload);
    }

    public function testThePickerListsTheShownColumnsInOrderThenTheHiddenOnes(): void
    {
        $state = $this->working()->read(self::LIST, [], null, ['cols' => 'email,name']);

        self::assertSame([
            ['key' => 'name', 'label' => 'party.column.name', 'visible' => true, 'locked' => true],
            ['key' => 'email', 'label' => 'party.column.email', 'visible' => true, 'locked' => false],
            ['key' => 'kind', 'label' => 'party.column.kind', 'visible' => false, 'locked' => false],
            ['key' => 'phone', 'label' => 'party.column.phone', 'visible' => false, 'locked' => false],
        ], $state->picker());
    }

    public function testThePickerLeavesOutWhatTheContextDoesNotOffer(): void
    {
        $working = $this->working();

        self::assertSame(['name', 'kind', 'email', 'phone'], array_column($working->read(self::LIST, [], null, [])->picker(), 'key'));
        self::assertSame(['name', 'kind', 'email', 'phone', 'archivedAt'], array_column($working->read(self::LIST, ['archived' => true], null, [])->picker(), 'key'));
    }

    public function testTheLockedColumnLeadsWhereverTheAddressPutsIt(): void
    {
        $state = $this->working()->read(self::LIST, [], null, ['cols' => 'email,name']);

        self::assertSame(['name', 'email'], $state->display->keys());
    }

    public function working(): WorkingDisplay
    {
        $provider = new class implements ListColumns {
            public function columns(string $list): iterable
            {
                if ('party.contacts' !== $list) {
                    return [];
                }
                $text = static fn (string $key): \Closure => static fn (array $row): string => \is_string($row[$key] ?? null) ? $row[$key] : '';

                return [
                    new Column('name', 'party.column.name', $text('name'), defaultPosition: 10, locked: true),
                    new Column('kind', 'party.column.kind', $text('kind'), defaultPosition: 20),
                    new Column('email', 'party.column.email', $text('email'), defaultPosition: 30),
                    new Column('phone', 'party.column.phone', $text('phone'), defaultVisible: false, defaultPosition: 40),
                    new Column('archivedAt', 'party.column.archivedAt', $text('archivedAt'), defaultVisible: false, defaultPosition: 50, available: static fn (array $context): bool => true === ($context['archived'] ?? false)),
                ];
            }
        };

        return new WorkingDisplay(new ListColumnRegistry([$provider], new IdentityTranslator()));
    }
}
