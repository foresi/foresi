<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Ui\Column;

use Foresi\Shared\Domain\View\AttributeType;
use Foresi\Tests\Ui\Support\TestColumns;
use Foresi\Ui\Column\RegistryWorkingDisplay;
use Foresi\Ui\Contract\Attribute;
use Foresi\Ui\Contract\DataSource;
use Foresi\Ui\Contract\ResolvedColumn;
use PHPUnit\Framework\TestCase;

final class RegistryWorkingDisplayTest extends TestCase
{
    private const string LIST = 'party.contacts';

    public function testWithoutAWorkingStateTheListShowsItsViewUnchanged(): void
    {
        $state = $this->working()->read(self::LIST, [], null, []);

        self::assertSame(['name', 'kind', 'email'], $state->display->keys());
        self::assertFalse($state->modified);
        self::assertSame([], $state->query);
        self::assertNull($state->payload);
    }

    public function testTheViewsStoredDisplayIsTheBase(): void
    {
        $saved = ['columns' => [['key' => 'name'], ['key' => 'email', 'width' => 200]], 'group_by' => 'kind'];
        $state = $this->working()->read(self::LIST, [], $saved, []);

        self::assertSame(['name', 'email'], $state->display->keys());
        self::assertFalse($state->modified);
        self::assertSame($saved, $state->payload);
    }

    private const array PINS = ['fields' => ['kind', 'roles', 'city', 'archived'], 'default' => ['roles', 'kind']];

    public function testAViewWithoutQuickFiltersOfItsOwnPinsTheListsDefaults(): void
    {
        $state = $this->working()->read(self::LIST, [], null, [], pins: self::PINS);

        self::assertSame(['roles', 'kind'], $state->pinned);
        self::assertFalse($state->modified);
        self::assertSame([], $state->query);
    }

    public function testTheViewsStoredQuickFiltersAreTheBaseAndAFieldGoneIsLeftOut(): void
    {
        $saved = ['columns' => [['key' => 'name']], 'pinned' => ['city', 'gone']];
        $state = $this->working()->read(self::LIST, [], $saved, [], pins: self::PINS);

        self::assertSame(['city'], $state->pinned);
        self::assertFalse($state->modified);
        self::assertSame($saved, $state->payload);
        self::assertSame([], $this->working()->read(self::LIST, [], ['pinned' => []], [], pins: self::PINS)->pinned, 'A view that pins none shows none.');
    }

    public function testQuickFiltersFromTheAddressMarkTheViewModifiedAndGoWithItsDisplay(): void
    {
        $state = $this->working()->read(self::LIST, [], null, ['pins' => 'archived,kind,archived,unknown'], pins: self::PINS);

        self::assertSame(['archived', 'kind'], $state->pinned);
        self::assertTrue($state->modified);
        self::assertSame(['pins' => 'archived,kind'], $state->query);
        self::assertSame(['archived', 'kind'], $state->payload['pinned'] ?? null, 'Saving the view keeps them with its columns.');
        $columns = $state->payload['columns'] ?? null;
        self::assertIsArray($columns);
        self::assertSame(['name', 'kind', 'email'], array_column($columns, 'key'));
    }

    public function testAnEmptyPinsPinsNoneAndAFormWithoutJavaScriptSendsAList(): void
    {
        $none = $this->working()->read(self::LIST, [], null, ['pins' => ''], pins: self::PINS);
        self::assertSame([], $none->pinned);
        self::assertSame(['pins' => ''], $none->query);

        $listed = $this->working()->read(self::LIST, [], null, ['pins' => ['', 'city']], pins: self::PINS);
        self::assertSame(['city'], $listed->pinned);

        self::assertSame(['city', 'kind'], $this->working()->read(self::LIST, [], null, ['pins' => 'city, kind'], pins: self::PINS)->pinned, 'A space around a name is no part of it.');
        self::assertSame(['city', 'kind'], RegistryWorkingDisplay::pins([' city ', 'kind'], ['kind', 'city']));
    }

    public function testTheViewsOwnQuickFiltersInTheAddressAreNoChange(): void
    {
        $state = $this->working()->read(self::LIST, [], null, ['pins' => 'roles,kind'], pins: self::PINS);

        self::assertFalse($state->modified);
        self::assertSame([], $state->query);
    }

    public function testAtMostFourQuickFiltersArePinned(): void
    {
        $pins = ['fields' => ['a', 'b', 'c', 'd', 'e'], 'default' => []];

        self::assertSame(['a', 'b', 'c', 'd'], $this->working()->read(self::LIST, [], null, ['pins' => 'a,b,c,d,e'], pins: $pins)->pinned);
    }

    public function testChangingTheColumnsKeepsTheQuickFiltersOfTheView(): void
    {
        $state = $this->working()->read(self::LIST, [], ['pinned' => ['city']], ['cols' => 'name,phone'], pins: self::PINS);

        self::assertSame(['city'], $state->payload['pinned'] ?? null);
        self::assertSame(['cols' => 'name,phone'], $state->query);
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
        $state = $this->working()->read(self::LIST, ['archived' => true], ['columns' => [['key' => 'name'], ['key' => 'archivedAt', 'width' => 120]]], ['pins' => '']);

        self::assertSame(['name', 'archived_at'], $state->display->keys());
        self::assertSame([null, 120], array_map(static fn (ResolvedColumn $column): ?int => $column->width, $state->display->columns));
    }

    public function testAWorkingStateThatShowsTheViewIsNoChangeAndLeavesTheAddress(): void
    {
        $state = $this->working()->read(self::LIST, [], null, ['cols' => 'name,kind,email', 'density' => 'dense']);

        self::assertFalse($state->modified);
        self::assertSame([], $state->query);
    }

    public function testAnOldDensityInTheAddressIsNoChange(): void
    {
        $state = $this->working()->read(self::LIST, [], null, ['density' => 'dense']);

        self::assertSame(['name', 'kind', 'email'], $state->display->keys());
        self::assertFalse($state->modified);
        self::assertSame([], $state->query);
        self::assertNull($state->payload);
    }

    public function testAKeptColumnKeepsTheWidthTheViewGivesIt(): void
    {
        $saved = ['columns' => [['key' => 'name', 'width' => 240], ['key' => 'email', 'width' => 180]]];
        $state = $this->working()->read(self::LIST, [], $saved, ['cols' => 'name,kind,email']);

        self::assertSame([240, null, 180], array_map(static fn (ResolvedColumn $column): ?int => $column->width, $state->display->columns));
        self::assertSame(['columns' => [['key' => 'name', 'width' => 240], ['key' => 'kind'], ['key' => 'email', 'width' => 180]]], $state->payload);
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

    public function testTheColumnsChangeAndAnOldDensityNextToThemIsLeftOut(): void
    {
        $state = $this->working()->read(self::LIST, [], ['columns' => [['key' => 'name']], 'density' => 'dense'], ['cols' => 'name,phone', 'density' => 'dense']);

        self::assertTrue($state->modified);
        self::assertSame(['cols' => 'name,phone'], $state->query);
        self::assertSame(['columns' => [['key' => 'name'], ['key' => 'phone']]], $state->payload);
    }

    public function testTheLockedColumnLeadsThePayloadAndTheRestKeepTheirOrder(): void
    {
        $state = $this->working()->read(self::LIST, [], null, ['cols' => 'email, name ,,email, kind']);

        self::assertSame(['name', 'email', 'kind'], $state->display->keys());
        self::assertSame(['columns' => [['key' => 'name'], ['key' => 'email'], ['key' => 'kind']]], $state->payload);
    }

    public function testAListOfColumnsWithOtherThanTextIsReadLeniently(): void
    {
        $state = $this->working()->read(self::LIST, [], null, ['cols' => ['x' => 'phone', 'y' => ['email'], 'z' => 'kind']]);

        self::assertSame(['name', 'phone', 'kind'], $state->display->keys());
        self::assertSame(['columns' => [['key' => 'name'], ['key' => 'phone'], ['key' => 'kind']]], $state->payload);
    }

    public function testTheAddressNamesAtMostAHundredColumns(): void
    {
        $keys = [...array_map(static fn (int $i): string => 'gone'.$i, range(1, 100)), 'phone'];
        $state = $this->working()->read(self::LIST, [], null, ['cols' => implode(',', $keys)]);

        self::assertSame(['name'], $state->display->keys());
    }

    public function testAGroupingFromTheAddressIsAChangeKeptInThePayload(): void
    {
        $state = $this->working()->read(self::LIST, [], null, ['group' => 'email'], groups: ['kind', 'email']);

        self::assertTrue($state->modified);
        self::assertSame('email', $state->display->groupBy);
        self::assertSame(['group' => 'email'], $state->query);
        self::assertSame(['columns' => [['key' => 'name'], ['key' => 'kind'], ['key' => 'email']], 'group_by' => 'email'], $state->payload);
    }

    public function testTheViewsGroupingOrTheListsDefaultIsNoChange(): void
    {
        $groups = ['kind', 'email'];
        self::assertFalse($this->working()->read(self::LIST, [], null, ['group' => 'kind'], groups: $groups)->modified, 'the list\'s default');
        $saved = ['group_by' => 'email'];
        $state = $this->working()->read(self::LIST, [], $saved, ['group' => 'email'], groups: $groups);
        self::assertFalse($state->modified);
        self::assertSame([], $state->query);
        $state = $this->working()->read(self::LIST, [], $saved, ['group' => 'kind'], groups: $groups);
        self::assertTrue($state->modified, 'back to the default from the view\'s own');
        self::assertSame(['group' => 'kind'], $state->query);
    }

    public function testAGroupingTheListDoesNotOfferIsIgnored(): void
    {
        $groups = ['kind', 'email'];
        self::assertFalse($this->working()->read(self::LIST, [], null, ['group' => 'phone'], groups: $groups)->modified);
        self::assertFalse($this->working()->read(self::LIST, [], ['group_by' => 'phone'], ['group' => 'kind'], groups: $groups)->modified, 'a stored grouping the list lost is its default');
        self::assertFalse($this->working()->read(self::LIST, [], null, ['group' => 'kind'])->modified, 'a list that does not group');
        self::assertSame([], $this->working()->read(self::LIST, [], null, ['group' => 'kind'])->query);
    }

    public function testColumnsChangedKeepTheGroupingAsked(): void
    {
        $state = $this->working()->read(self::LIST, [], null, ['cols' => 'name,email', 'group' => 'email'], groups: ['kind', 'email']);

        self::assertSame(['cols' => 'name,email', 'group' => 'email'], $state->query);
        self::assertSame('email', $state->payload['group_by'] ?? null);
    }

    public function testDraggedWidthsAreAChangeKeptInThePayloadAndTheAddress(): void
    {
        $state = $this->working()->read(self::LIST, [], null, ['widths' => 'name:240,kind:120,email:300']);

        self::assertTrue($state->modified);
        self::assertSame([240, 120, 300], array_map(static fn (ResolvedColumn $column): ?int => $column->width, $state->display->columns));
        self::assertSame(['widths' => 'name:240,kind:120,email:300'], $state->query);
        self::assertSame(['columns' => [['key' => 'name', 'width' => 240], ['key' => 'kind', 'width' => 120], ['key' => 'email', 'width' => 300]]], $state->payload);
    }

    public function testTheViewsOwnWidthsAreNoChangeAndEmptyWidthsAreTheViewsOwn(): void
    {
        $saved = ['columns' => [['key' => 'name', 'width' => 240], ['key' => 'email', 'width' => 180]]];

        self::assertFalse($this->working()->read(self::LIST, [], $saved, ['widths' => 'name:240,email:180'])->modified);
        $state = $this->working()->read(self::LIST, [], $saved, ['widths' => '']);
        self::assertFalse($state->modified);
        self::assertSame([240, 180], array_map(static fn (ResolvedColumn $column): ?int => $column->width, $state->display->columns));
    }

    public function testWidthsAskedReplaceTheViewsAllAtOnce(): void
    {
        $saved = ['columns' => [['key' => 'name', 'width' => 240], ['key' => 'email', 'width' => 180]]];
        $state = $this->working()->read(self::LIST, [], $saved, ['widths' => 'email:260']);

        self::assertSame([null, 260], array_map(static fn (ResolvedColumn $column): ?int => $column->width, $state->display->columns));
        self::assertSame(['widths' => 'email:260'], $state->query);
    }

    public function testWidthsGoWithTheColumnsAsked(): void
    {
        $state = $this->working()->read(self::LIST, [], null, ['cols' => 'name,phone', 'widths' => 'name:200,phone:150,email:400']);

        self::assertSame(['columns' => [['key' => 'name', 'width' => 200], ['key' => 'phone', 'width' => 150]]], $state->payload);
        self::assertSame(['cols' => 'name,phone', 'widths' => 'name:200,phone:150'], $state->query, 'a width of a column not shown is left out');
    }

    public function testWidthsAreReadLenientlyAndKeptBetweenTheColumnsMinimumAndTheMaximum(): void
    {
        $state = $this->working()->read(self::LIST, [], null, ['widths' => 'name:10, kind:9999,email:abc,:120,phone,kind:300']);

        self::assertSame([64, 1200, null], array_map(static fn (ResolvedColumn $column): ?int => $column->width, $state->display->columns));
        self::assertSame(['widths' => 'name:64,kind:1200'], $state->query);
        self::assertNull(RegistryWorkingDisplay::widths(['name' => 200]), 'only text names widths');
    }

    public function testAWidthIsAKeyAndAWholeNumberOfPxAloneInItsPair(): void
    {
        self::assertSame(['kind' => 300, 'email' => 140], RegistryWorkingDisplay::widths(' kind : 300 ,phone name:200,name:2000x,email:140,phone:12345,phone:0,phone:05,email:160'));
        self::assertNull(RegistryWorkingDisplay::widths(''), 'empty widths are the view\'s own');
    }

    public function testDroppingAColumnWithAWidthChangesOnlyTheColumns(): void
    {
        $saved = ['columns' => [['key' => 'name'], ['key' => 'kind'], ['key' => 'email', 'width' => 180]]];
        $state = $this->working()->read(self::LIST, [], $saved, ['cols' => 'name,kind']);

        self::assertTrue($state->modified);
        self::assertSame(['cols' => 'name,kind'], $state->query);
    }

    public function testEverythingAskedAtOnceGoesInThePayload(): void
    {
        $state = $this->working()->read(self::LIST, [], null, ['cols' => 'name,email', 'group' => 'email', 'pins' => 'city', 'widths' => 'email:250'], groups: ['kind', 'email'], pins: self::PINS);

        self::assertSame(['columns' => [['key' => 'name'], ['key' => 'email', 'width' => 250]], 'group_by' => 'email', 'pinned' => ['city']], $state->payload);
    }

    public function testALayoutOrWeekendsAskedOfAListWithoutACalendarAreIgnored(): void
    {
        $state = $this->working()->read(self::LIST, [], null, ['layout' => 'calendar', 'weekends' => '0']);

        self::assertFalse($state->modified);
        self::assertSame([], $state->query);
        self::assertTrue($state->display->weekends);
    }

    public function testACalendarAskedIsAChangeKeptInThePayloadAndTheAddress(): void
    {
        $state = $this->working()->read(self::LIST, [], null, ['layout' => 'calendar', 'weekends' => '0'], calendar: true);

        self::assertTrue($state->modified);
        self::assertSame(['layout' => 'calendar', 'weekends' => '0'], $state->query);
        self::assertSame(['columns' => [['key' => 'name'], ['key' => 'kind'], ['key' => 'email']], 'layout' => 'calendar', 'weekends' => false], $state->payload);
        self::assertSame('calendar', $state->display->layout);
        self::assertFalse($state->display->weekends);
    }

    public function testAViewThatHidesTheWeekendsShowsThemAgainOnlyWhenAsked(): void
    {
        $saved = ['layout' => 'calendar', 'weekends' => false];
        $state = $this->working()->read(self::LIST, [], $saved, [], calendar: true);
        self::assertFalse($state->modified, 'the view\'s own');
        self::assertFalse($state->display->weekends);

        $state = $this->working()->read(self::LIST, [], $saved, ['weekends' => '1'], calendar: true);
        self::assertTrue($state->modified);
        self::assertSame(['weekends' => '1'], $state->query);
        self::assertTrue($state->display->weekends);
    }

    public function working(): RegistryWorkingDisplay
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

        return new RegistryWorkingDisplay(TestColumns::registry($provider));
    }
}
