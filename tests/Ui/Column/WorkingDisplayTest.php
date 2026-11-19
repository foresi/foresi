<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Ui\Column;

use Foresi\Shared\Domain\View\AttributeType;
use Foresi\Tests\Ui\Support\TestColumns;
use Foresi\Ui\Column\ResolvedColumn;
use Foresi\Ui\Column\WorkingDisplay;
use Foresi\Ui\Contract\Attribute;
use Foresi\Ui\Contract\DataSource;
use PHPUnit\Framework\TestCase;

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

    public function testAnOlderKeyInTheAddressShowsItsColumnAndIsWrittenBackUnderItsKey(): void
    {
        $state = $this->working()->read(self::LIST, ['archived' => true], null, ['cols' => 'name,archivedAt']);

        self::assertSame(['name', 'archived_at'], $state->display->keys());
        self::assertSame(['cols' => 'name,archived_at'], $state->query);
    }

    public function testAnOlderKeyAndItsKeyInTheAddressAreOneColumn(): void
    {
        $state = $this->working()->read(self::LIST, ['archived' => true], null, ['cols' => 'name, archivedAt ,archived_at']);

        self::assertSame(['name', 'archived_at'], $state->display->keys());
        self::assertSame(['cols' => 'name,archived_at'], $state->query);
    }

    public function testAnOlderKeyInTheViewsStoredDisplayShowsItsColumn(): void
    {
        $state = $this->working()->read(self::LIST, ['archived' => true], ['columns' => [['key' => 'name'], ['key' => 'archivedAt', 'width' => 120]]], ['density' => 'dense']);

        self::assertSame(['name', 'archived_at'], $state->display->keys());
        self::assertSame([null, 120], array_map(static fn (ResolvedColumn $column): ?int => $column->width, $state->display->columns));
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
        self::assertSame(['name', 'kind', 'email', 'phone', 'archived_at'], array_column($working->read(self::LIST, ['archived' => true], null, [])->picker(), 'key'));
    }

    public function testTheLockedColumnLeadsWhereverTheAddressPutsIt(): void
    {
        $state = $this->working()->read(self::LIST, [], null, ['cols' => 'email,name']);

        self::assertSame(['name', 'email'], $state->display->keys());
    }

    public function testTheColumnsAndTheDensityChangeTogether(): void
    {
        $state = $this->working()->read(self::LIST, [], null, ['cols' => 'name,phone', 'density' => 'dense']);

        self::assertTrue($state->modified);
        self::assertSame(['cols' => 'name,phone', 'density' => 'dense'], $state->query);
        self::assertSame(['columns' => [['key' => 'name'], ['key' => 'phone']], 'density' => 'dense'], $state->payload);
    }

    public function testAnUnknownDensityNextToColumnsKeepsTheViewsDensity(): void
    {
        $state = $this->working()->read(self::LIST, [], ['density' => 'dense'], ['cols' => 'name,phone', 'density' => 'huge']);

        self::assertSame(['columns' => [['key' => 'name'], ['key' => 'phone']], 'density' => 'dense'], $state->payload);
        self::assertSame(['cols' => 'name,phone'], $state->query);
    }

    public function testTheLockedColumnLeadsThePayloadAndTheRestKeepTheirOrder(): void
    {
        $state = $this->working()->read(self::LIST, [], null, ['cols' => 'email, name ,,email, kind']);

        self::assertSame(['name', 'email', 'kind'], $state->display->keys());
        self::assertSame(['columns' => [['key' => 'name'], ['key' => 'email'], ['key' => 'kind']], 'density' => 'comfortable'], $state->payload);
    }

    public function testAListOfColumnsWithOtherThanTextIsReadLeniently(): void
    {
        $state = $this->working()->read(self::LIST, [], null, ['cols' => ['x' => 'phone', 'y' => ['email'], 'z' => 'kind']]);

        self::assertSame(['name', 'phone', 'kind'], $state->display->keys());
        self::assertSame(['columns' => [['key' => 'name'], ['key' => 'phone'], ['key' => 'kind']], 'density' => 'comfortable'], $state->payload);
    }

    public function testTheAddressNamesAtMostAHundredColumns(): void
    {
        $keys = [...array_map(static fn (int $i): string => 'gone'.$i, range(1, 100)), 'phone'];
        $state = $this->working()->read(self::LIST, [], null, ['cols' => implode(',', $keys)]);

        self::assertSame(['name'], $state->display->keys());
    }

    public function testADensityChangeKeepsTheViewsColumnsWidthsAndGrouping(): void
    {
        $saved = ['columns' => [['key' => 'name', 'width' => 240], ['key' => 'kind']], 'group_by' => 'kind'];
        $state = $this->working()->read(self::LIST, [], $saved, ['density' => 'dense']);

        self::assertSame(['columns' => [['key' => 'name', 'width' => 240], ['key' => 'kind']], 'density' => 'dense', 'group_by' => 'kind'], $state->payload);
        self::assertSame(['density' => 'dense'], $state->query);
    }

    public function working(): WorkingDisplay
    {
        $provider = new class implements DataSource {
            public function attributes(string $list): iterable
            {
                if ('party.contacts' !== $list) {
                    return [];
                }
                $text = static fn (string $key): \Closure => static fn (array $row): string => \is_string($row[$key] ?? null) ? $row[$key] : '';

                return [
                    new Attribute('name', 'party.column.name', AttributeType::Text, $text('name'), defaultVisible: true, defaultPosition: 10, locked: true),
                    new Attribute('kind', 'party.column.kind', AttributeType::Text, $text('kind'), defaultVisible: true, defaultPosition: 20),
                    new Attribute('email', 'party.column.email', AttributeType::Text, $text('email'), defaultVisible: true, defaultPosition: 30),
                    new Attribute('phone', 'party.column.phone', AttributeType::Text, $text('phone'), defaultPosition: 40),
                    new Attribute('archived_at', 'party.column.archived_at', AttributeType::Text, $text('archived_at'), defaultPosition: 50, aliases: ['archivedAt'], available: static fn (array $context): bool => true === ($context['archived'] ?? false)),
                ];
            }
        };

        return new WorkingDisplay(TestColumns::registry($provider));
    }
}
