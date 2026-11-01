<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Ui\Navigation;

use App\Ui\Navigation\NavigationCountProvider;
use App\Ui\Navigation\NavigationItem;
use App\Ui\Navigation\NavigationItemProvider;
use App\Ui\Navigation\NavigationRegistry;
use PHPUnit\Framework\TestCase;

final class NavigationRegistryTest extends TestCase
{
    public function testGroupsComeInTheFixedOrderWhateverTheRegistrationOrder(): void
    {
        $registry = $this->registry(
            new NavigationItem('extensions', 'e', 'plug', 'r_e'),
            new NavigationItem('work', 'w', 'check', 'r_w'),
            new NavigationItem('contacts', 'c', 'users', 'r_c'),
            new NavigationItem('money', 'm', 'landmark', 'r_m'),
            new NavigationItem('sales', 's', 'target', 'r_s'),
            new NavigationItem('main', 'a', 'layout-grid', 'r_a'),
            new NavigationItem('settings', 'x', 'settings', 'r_x'),
        );

        self::assertSame(['main', 'sales', 'money', 'contacts', 'work', 'extensions', 'settings'], array_map(static fn (array $g): string => $g['key'], $registry->groups(null, true)));
    }

    public function testEmptyGroupsAreLeftOut(): void
    {
        $groups = $this->registry(new NavigationItem('money', 'm', 'landmark', 'r_m'))->groups(null, true);

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

        self::assertSame(['first', 'tie-a', 'tie-b', 'late'], array_map(static fn (\App\Ui\Navigation\NavigationEntry $i): string => $i->labelKey, $groups[0]['items']));
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

    public function testNothingIsCurrentWithoutARoute(): void
    {
        $groups = $this->registry(new NavigationItem('main', 'a', 'plus', 'r1'))->groups(null, true);

        self::assertFalse($groups[0]['items'][0]->current);
    }

    public function testCountsAreEvaluatedInsideAnActiveTenant(): void
    {
        $groups = $this->registry(new NavigationItem('main', 'a', 'plus', 'r1', $this->counter(7)), new NavigationItem('main', 'b', 'plus', 'r2'))->groups(null, true);

        self::assertSame(7, $groups[0]['items'][0]->count);
        self::assertNull($groups[0]['items'][1]->count);
    }

    public function testCountProvidersAreNeverCalledWithoutAnActiveTenant(): void
    {
        $counter = new class implements NavigationCountProvider {
            public int $calls = 0;

            public function count(): ?int
            {
                ++$this->calls;

                throw new \LogicException('Would run tenant-owned queries without a tenant.');
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

    public function testUnknownGroupIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

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
