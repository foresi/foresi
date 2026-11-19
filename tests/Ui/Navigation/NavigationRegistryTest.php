<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Ui\Navigation;

use Foresi\Ui\Contract\Navigation\CoreNavigationAreas;
use Foresi\Ui\Contract\Navigation\NavigationArea;
use Foresi\Ui\Contract\Navigation\NavigationAreaProvider;
use Foresi\Ui\Contract\Navigation\NavigationCountProvider;
use Foresi\Ui\Contract\Navigation\NavigationItem;
use Foresi\Ui\Contract\Navigation\NavigationItemProvider;
use Foresi\Ui\Navigation\NavigationRegistry;
use PHPUnit\Framework\TestCase;

final class NavigationRegistryTest extends TestCase
{
    public function testGroupsComeInTheFixedOrderWhateverTheRegistrationOrder(): void
    {
        $registry = $this->registry(
            new NavigationItem('extensions', 'e', 'plug', 'r_e', area: 'extensions'),
            new NavigationItem('work', 'w', 'check', 'r_w'),
            new NavigationItem('contacts', 'c', 'users', 'r_c'),
            new NavigationItem('money', 'm', 'landmark', 'r_m', area: 'money'),
            new NavigationItem('sales', 's', 'target', 'r_s'),
            new NavigationItem('main', 'a', 'layout-grid', 'r_a'),
            new NavigationItem('settings', 'x', 'settings', 'r_x'),
        );

        self::assertSame(['main', 'sales', 'money', 'contacts', 'work', 'extensions', 'settings'], array_map(static fn (array $g): string => $g['key'], $registry->groups(null, true)));
    }

    public function testEmptyGroupsAreLeftOut(): void
    {
        $groups = $this->registry(new NavigationItem('money', 'm', 'landmark', 'r_m', area: 'money'))->groups(null, true);

        self::assertCount(1, $groups);
        self::assertSame('money', $groups[0]['key']);
        self::assertSame('nav.group.money', $groups[0]['labelKey']);
    }

    public function testItemsAreOrderedByWeightThenRegistrationOrder(): void
    {
        $groups = $this->registry(
            new NavigationItem('main', 'late', 'plus', 'r1', weight: 200),
            new NavigationItem('main', 'first', 'plus', 'r2', weight: 10),
            new NavigationItem('main', 'tie-a', 'plus', 'r3'),
            new NavigationItem('main', 'tie-b', 'plus', 'r4'),
        )->groups(null, true);

        self::assertSame(['first', 'tie-a', 'tie-b', 'late'], array_map(static fn (\Foresi\Ui\Navigation\NavigationEntry $i): string => $i->labelKey, $groups[0]['items']));
    }

    public function testItemsOfSeveralProvidersAreMerged(): void
    {
        $registry = new NavigationRegistry([
            $this->provider(new NavigationItem('main', 'a', 'plus', 'r1')),
            $this->provider(new NavigationItem('main', 'b', 'plus', 'r2')),
        ]);

        self::assertCount(2, $registry->groups(null, true)[0]['items']);
    }

    public function testTheItemOfTheCurrentRouteIsMarked(): void
    {
        $groups = $this->registry(new NavigationItem('main', 'a', 'plus', 'r1'), new NavigationItem('main', 'b', 'plus', 'r2'))->groups('r2', true);

        self::assertFalse($groups[0]['items'][0]->current);
        self::assertTrue($groups[0]['items'][1]->current);
    }

    public function testTheItemStaysCurrentOnTheRoutesOfItsPrefixes(): void
    {
        $item = new NavigationItem('main', 'a', 'plus', 'app_things', routePrefixes: ['app_thing_', 'app_other']);

        self::assertTrue($this->registry($item)->groups('app_thing_edit', true)[0]['items'][0]->current);
        self::assertTrue($this->registry($item)->groups('app_other', true)[0]['items'][0]->current);
        self::assertFalse($this->registry($item)->groups('app_thingy', true)[0]['items'][0]->current);
        self::assertFalse($this->registry($item)->groups(null, true)[0]['items'][0]->current);
    }

    public function testAnItemOfItsOwnWinsOverAnotherItemsPrefix(): void
    {
        $list = new NavigationItem('sales', 'a', 'plus', 'app_invoices', routePrefixes: ['app_invoice']);
        $log = new NavigationItem('sales', 'b', 'plus', 'app_invoice_sends');

        $onLog = $this->registry($list, $log)->groups('app_invoice_sends', true)[0]['items'];
        $onEdit = $this->registry($list, $log)->groups('app_invoice_edit', true)[0]['items'];

        self::assertSame([false, true], [$onLog[0]->current, $onLog[1]->current]);
        self::assertSame([true, false], [$onEdit[0]->current, $onEdit[1]->current]);
    }

    public function testNothingIsCurrentWithoutARoute(): void
    {
        $groups = $this->registry(new NavigationItem('main', 'a', 'plus', 'r1'))->groups(null, true);

        self::assertFalse($groups[0]['items'][0]->current);
    }

    public function testCountsAreEvaluatedForAMember(): void
    {
        $groups = $this->registry(new NavigationItem('main', 'a', 'plus', 'r1', $this->counter(7)), new NavigationItem('main', 'b', 'plus', 'r2'))->groups(null, true);

        self::assertSame(7, $groups[0]['items'][0]->count);
        self::assertNull($groups[0]['items'][1]->count);
    }

    public function testCountProvidersAreNeverCalledWithoutAnActiveMembership(): void
    {
        $counter = new class implements NavigationCountProvider {
            public int $calls = 0;

            public function count(): ?int
            {
                ++$this->calls;

                throw new \LogicException('Would run queries without an active membership.');
            }
        };

        $groups = $this->registry(new NavigationItem('main', 'a', 'plus', 'r1', $counter))->groups(null, false);

        self::assertSame(0, $counter->calls);
        self::assertNull($groups[0]['items'][0]->count);
    }

    public function testAProviderMayDeclineToCount(): void
    {
        $groups = $this->registry(new NavigationItem('main', 'a', 'plus', 'r1', $this->counter(null)))->groups(null, true);

        self::assertNull($groups[0]['items'][0]->count);
    }

    public function testACountAboveItsCapIsWrittenAsTheCapWithAPlus(): void
    {
        $groups = $this->registry(
            new NavigationItem('main', 'a', 'plus', 'r1', $this->counter(100), countCap: 99),
            new NavigationItem('main', 'b', 'plus', 'r2', $this->counter(99), countCap: 99),
            new NavigationItem('main', 'c', 'plus', 'r3', $this->counter(1000)),
            new NavigationItem('main', 'd', 'plus', 'r4', $this->counter(null), countCap: 99),
        )->groups(null, true);

        self::assertSame(['99+', '99', '1000', null], array_map(static fn (\Foresi\Ui\Navigation\NavigationEntry $entry): ?string => $entry->countText(), $groups[0]['items']));
        self::assertSame(100, $groups[0]['items'][0]->count);
        self::assertSame(99, $groups[0]['items'][0]->countCap);
    }

    public function testACapOfOneIsAllowedAndBelowItRefused(): void
    {
        self::assertSame(1, new NavigationItem('main', 'a', 'plus', 'r1', countCap: 1)->countCap);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A count cap is a positive number.');

        new NavigationItem('main', 'a', 'plus', 'r1', countCap: 0);
    }

    public function testUnknownGroupIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Unknown navigation group "nope"');

        new NavigationItem('nope', 'a', 'plus', 'r1');
    }

    public function testIconOutsideTheLockedSetIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new NavigationItem('main', 'a', 'no-such-icon', 'r1');
    }

    public function testRouteAndLabelAreRequired(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new NavigationItem('main', '', 'plus', 'r1');
    }

    public function testRouteIsRequired(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new NavigationItem('main', 'a', 'plus', '');
    }

    public function testCoreProvidesSixBusinessAreasAndSettings(): void
    {
        $areas = iterator_to_array(new CoreNavigationAreas()->areas(), false);

        self::assertSame(['overview', 'sales', 'purchasing', 'money', 'contacts', 'projects', 'settings'], array_map(static fn (NavigationArea $a): string => $a->id, $areas));
        self::assertSame(['layout-grid', 'trending-up', 'shopping-bag', 'landmark', 'users', 'square-kanban', 'settings'], array_map(static fn (NavigationArea $a): string => $a->icon, $areas));
        self::assertSame([10, 20, 30, 40, 50, 60, 1000], array_map(static fn (NavigationArea $a): int => $a->weight, $areas));
        self::assertSame('nav.area.sales', $areas[1]->labelKey);
        self::assertSame('nav.area_caption.sales', $areas[1]->captionKey);
    }

    public function testAGroupThatMapsToOneAreaGivesTheAreaOnItsOwn(): void
    {
        self::assertSame(
            ['overview', 'sales', 'contacts', 'projects', 'settings'],
            array_map(static fn (string $group): string => new NavigationItem($group, 'a', 'plus', 'r1')->area, ['main', 'sales', 'contacts', 'work', 'settings']),
        );
        self::assertSame('purchasing', new NavigationItem('money', 'a', 'plus', 'r1', area: 'purchasing')->area);
    }

    public function testAnItemOfAGroupThatSpansAreasMustNameItsArea(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A navigation item in group "money" needs an area.');

        new NavigationItem('money', 'a', 'plus', 'r1');
    }

    public function testAMalformedAreaIdIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new NavigationItem('main', 'a', 'plus', 'r1', area: 'Not An Id');
    }

    public function testAnAreaWeighsAHundredByDefault(): void
    {
        self::assertSame(100, new NavigationArea('sales', 'l', 'c', 'plus')->weight);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function malformedAreas(): iterable
    {
        yield 'uppercase id' => ['Sales', 'l', 'c'];
        yield 'id with trailing junk' => ['sales-x', 'l', 'c'];
        yield 'no label' => ['sales', '', 'c'];
        yield 'no caption' => ['sales', 'l', ''];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedAreas')]
    public function testAMalformedAreaIsRefused(string $id, string $label, string $caption): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new NavigationArea($id, $label, $caption, 'plus');
    }

    public function testAnItemAreaWithTrailingJunkIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new NavigationItem('main', 'a', 'plus', 'r1', area: 'sales-x');
    }

    public function testAnAreaWithAnIconOutsideTheLockedSetIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new NavigationArea('sales', 'l', 'c', 'no-such-icon');
    }

    public function testItemsAreGroupedByAreaWithTheirGroupsAsSections(): void
    {
        $areas = $this->areaRegistry(
            new NavigationItem('money', 'bank', 'landmark', 'r_bank', area: 'money'),
            new NavigationItem('money', 'costs', 'file-text', 'r_costs', area: 'purchasing'),
            new NavigationItem('sales', 'invoices', 'receipt-text', 'r_inv'),
            new NavigationItem('main', 'dashboard', 'layout-grid', 'r_dash'),
            new NavigationItem('main', 'notifications', 'inbox', 'r_notif'),
            new NavigationItem('settings', 'people', 'user', 'r_people'),
        )->areas('r_costs', true);

        self::assertSame(['overview', 'sales', 'purchasing', 'money', 'settings'], array_map(static fn (array $a): string => $a['area']->id, $areas));
        self::assertSame(['main'], array_map(static fn (array $g): string => $g['key'], $areas[0]['groups']));
        self::assertSame(['dashboard', 'notifications'], array_map(static fn (\Foresi\Ui\Navigation\NavigationEntry $e): string => $e->labelKey, $areas[0]['groups'][0]['items']));
        self::assertSame(['costs'], array_map(static fn (\Foresi\Ui\Navigation\NavigationEntry $e): string => $e->labelKey, $areas[2]['groups'][0]['items']));
        self::assertSame([false, false, true, false, false], array_map(static fn (array $a): bool => $a['current'], $areas));
    }

    public function testAreasWithoutItemsAreLeftOut(): void
    {
        $areas = $this->areaRegistry(new NavigationItem('main', 'shared', 'share-2', 'r1'))->areas(null, true);

        self::assertSame(['overview'], array_map(static fn (array $a): string => $a['area']->id, $areas));
        self::assertFalse($areas[0]['current']);
    }

    public function testAreasComeByWeightWithSettingsAlwaysLast(): void
    {
        $registry = new NavigationRegistry(
            [$this->provider(
                new NavigationItem('settings', 's', 'settings', 'r1'),
                new NavigationItem('extensions', 'b', 'puzzle', 'r2', area: 'late'),
                new NavigationItem('extensions', 'a', 'puzzle', 'r3', area: 'early'),
            )],
            [$this->areas(
                new NavigationArea('settings', 'l', 'c', 'settings', 1),
                new NavigationArea('late', 'l', 'c', 'puzzle', 50),
                new NavigationArea('early', 'l', 'c', 'puzzle', 5),
            )],
        );

        self::assertSame(['early', 'late', 'settings'], array_map(static fn (array $a): string => $a['area']->id, $registry->areas(null, true)));
    }

    public function testAreasOfEqualWeightKeepTheirRegistrationOrder(): void
    {
        $registry = new NavigationRegistry(
            [$this->provider(...array_map(static fn (string $id): NavigationItem => new NavigationItem('extensions', $id, 'puzzle', 'r_'.$id, area: $id), ['c', 'a', 'b']))],
            [$this->areas(new NavigationArea('b', 'l', 'c', 'puzzle'), new NavigationArea('c', 'l', 'c', 'puzzle')), $this->areas(new NavigationArea('a', 'l', 'c', 'puzzle'))],
        );

        self::assertSame(['b', 'c', 'a'], array_map(static fn (array $a): string => $a['area']->id, $registry->areas(null, true)));
    }

    public function testAnItemInAnUnregisteredAreaFailsLoudly(): void
    {
        $registry = $this->areaRegistry(new NavigationItem('extensions', 'orphan', 'puzzle', 'r1', area: 'nowhere'));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('Navigation item "orphan" names the area "nowhere", which no provider registers.');

        $registry->areas(null, true);
    }

    public function testAnAreaRegisteredTwiceFailsLoudly(): void
    {
        $registry = new NavigationRegistry([], [new CoreNavigationAreas(), $this->areas(new NavigationArea('sales', 'l', 'c', 'plus'))]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('Navigation area "sales" is registered twice.');

        $registry->areas(null, true);
    }

    public function testCountsAreEvaluatedOnceAndSkippedWithoutAMembershipInAreasToo(): void
    {
        $counter = new class implements NavigationCountProvider {
            public int $calls = 0;

            public function count(): int
            {
                return ++$this->calls;
            }
        };
        $registry = $this->areaRegistry(new NavigationItem('main', 'a', 'inbox', 'r1', $counter));

        self::assertNull($registry->areas(null, false)[0]['groups'][0]['items'][0]->count);
        self::assertSame(0, $counter->calls);
        self::assertSame(1, $registry->areas(null, true)[0]['groups'][0]['items'][0]->count);
    }

    public function testCurrentIsTheItemWhoseOwnRouteThePageIsBeforeAnyPrefix(): void
    {
        $registry = $this->registry(
            new NavigationItem('work', 'projects', 'columns-3', 'app_projects', routePrefixes: ['app_project', 'app_task']),
            new NavigationItem('work', 'mine', 'square-check-big', 'app_project_mine'),
        );

        self::assertSame('projects', $registry->current('app_task')?->labelKey);
        self::assertSame('mine', $registry->current('app_project_mine')?->labelKey);
        self::assertNull($registry->current('app_login'));
        self::assertNull($registry->current(null));
    }

    public function testCurrentRunsNoCount(): void
    {
        $count = new class implements NavigationCountProvider {
            public function count(): int
            {
                throw new \LogicException('The page title must not run a count.');
            }
        };

        self::assertSame('inbox', $this->registry(new NavigationItem('main', 'inbox', 'inbox', 'app_inbox', $count))->current('app_inbox')?->labelKey);
    }

    private function registry(NavigationItem ...$items): NavigationRegistry
    {
        return new NavigationRegistry([$this->provider(...$items)]);
    }

    private function provider(NavigationItem ...$items): NavigationItemProvider
    {
        return new readonly class(array_values($items)) implements NavigationItemProvider {
            /**
             * @param list<NavigationItem> $items
             */
            public function __construct(private array $items)
            {
            }

            public function items(): iterable
            {
                return $this->items;
            }
        };
    }

    private function areaRegistry(NavigationItem ...$items): NavigationRegistry
    {
        return new NavigationRegistry([$this->provider(...$items)], [new CoreNavigationAreas()]);
    }

    private function areas(NavigationArea ...$areas): NavigationAreaProvider
    {
        return new readonly class(array_values($areas)) implements NavigationAreaProvider {
            /**
             * @param list<NavigationArea> $areas
             */
            public function __construct(private array $areas)
            {
            }

            public function areas(): iterable
            {
                return $this->areas;
            }
        };
    }

    private function counter(?int $value): NavigationCountProvider
    {
        return new readonly class($value) implements NavigationCountProvider {
            public function __construct(private ?int $value)
            {
            }

            public function count(): ?int
            {
                return $this->value;
            }
        };
    }
}
