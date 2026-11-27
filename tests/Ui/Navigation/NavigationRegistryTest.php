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
use Foresi\Ui\Contract\Navigation\NavigationSection;
use Foresi\Ui\Navigation\NavigationEntry;
use Foresi\Ui\Navigation\NavigationRegistry;
use PHPUnit\Framework\TestCase;

final class NavigationRegistryTest extends TestCase
{
    public function testItemsAreOrderedByWeightThenRegistrationOrder(): void
    {
        $areas = $this->areaRegistry(
            new NavigationItem('overview', 'late', 'plus', 'r1', weight: 200),
            new NavigationItem('overview', 'first', 'plus', 'r2', weight: 10),
            new NavigationItem('overview', 'tie-a', 'plus', 'r3'),
            new NavigationItem('overview', 'tie-b', 'plus', 'r4'),
        )->areas(null, true);

        self::assertSame(['first', 'tie-a', 'tie-b', 'late'], self::labels($areas[0]['sections'][0]['items']));
    }

    public function testItemsOfSeveralProvidersAreMerged(): void
    {
        $registry = new NavigationRegistry([
            $this->provider(new NavigationItem('overview', 'a', 'plus', 'r1')),
            $this->provider(new NavigationItem('overview', 'b', 'plus', 'r2')),
        ], [new CoreNavigationAreas()]);

        self::assertCount(2, $registry->areas(null, true)[0]['sections'][0]['items']);
    }

    public function testTheItemOfTheCurrentRouteIsMarked(): void
    {
        $items = $this->overview('r2', new NavigationItem('overview', 'a', 'plus', 'r1'), new NavigationItem('overview', 'b', 'plus', 'r2'));

        self::assertFalse($items[0]->current);
        self::assertTrue($items[1]->current);
    }

    public function testTheItemStaysCurrentOnTheRoutesOfItsPrefixes(): void
    {
        $item = new NavigationItem('overview', 'a', 'plus', 'app_things', routePrefixes: ['app_thing_', 'app_other']);

        self::assertTrue($this->overview('app_thing_edit', $item)[0]->current);
        self::assertTrue($this->overview('app_other', $item)[0]->current);
        self::assertFalse($this->overview('app_thingy', $item)[0]->current);
        self::assertFalse($this->overview(null, $item)[0]->current);
    }

    public function testAnItemOfItsOwnWinsOverAnotherItemsPrefix(): void
    {
        $list = new NavigationItem('overview', 'a', 'plus', 'app_invoices', routePrefixes: ['app_invoice']);
        $log = new NavigationItem('overview', 'b', 'plus', 'app_invoice_sends');

        $onLog = $this->overview('app_invoice_sends', $list, $log);
        $onEdit = $this->overview('app_invoice_edit', $list, $log);

        self::assertSame([false, true], [$onLog[0]->current, $onLog[1]->current]);
        self::assertSame([true, false], [$onEdit[0]->current, $onEdit[1]->current]);
    }

    public function testTheItemWithTheLongerPrefixHasThePage(): void
    {
        $deals = new NavigationItem('overview', 'a', 'plus', 'app_deals', routePrefixes: ['app_deal']);
        $settings = new NavigationItem('overview', 'b', 'plus', 'app_deal_settings', routePrefixes: ['app_deal_stage_']);

        $onStageEdit = $this->overview('app_deal_stage_edit', $deals, $settings);
        $onDealStage = $this->overview('app_deal_stage', $deals, $settings);

        self::assertSame([false, true], [$onStageEdit[0]->current, $onStageEdit[1]->current]);
        self::assertSame([true, false], [$onDealStage[0]->current, $onDealStage[1]->current]);
        self::assertSame('b', $this->registry($deals, $settings)->current('app_deal_stage_new')?->labelKey);
    }

    public function testCountsAreEvaluatedForAMember(): void
    {
        $items = $this->overview(null, new NavigationItem('overview', 'a', 'plus', 'r1', $this->counter(7)), new NavigationItem('overview', 'b', 'plus', 'r2'));

        self::assertSame(7, $items[0]->count);
        self::assertNull($items[1]->count);
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

        $areas = $this->areaRegistry(new NavigationItem('overview', 'a', 'plus', 'r1', $counter))->areas(null, false);

        self::assertSame(0, $counter->calls);
        self::assertNull($areas[0]['sections'][0]['items'][0]->count);
    }

    public function testAProviderMayDeclineToCount(): void
    {
        self::assertNull($this->overview(null, new NavigationItem('overview', 'a', 'plus', 'r1', $this->counter(null)))[0]->count);
    }

    public function testACountAboveItsCapIsWrittenAsTheCapWithAPlus(): void
    {
        $items = $this->overview(
            null,
            new NavigationItem('overview', 'a', 'plus', 'r1', $this->counter(100), countCap: 99),
            new NavigationItem('overview', 'b', 'plus', 'r2', $this->counter(99), countCap: 99),
            new NavigationItem('overview', 'c', 'plus', 'r3', $this->counter(1000)),
            new NavigationItem('overview', 'd', 'plus', 'r4', $this->counter(null), countCap: 99),
        );

        self::assertSame(['99+', '99', '1000', null], array_map(static fn (NavigationEntry $entry): ?string => $entry->countText(), $items));
        self::assertSame(100, $items[0]->count);
        self::assertSame(99, $items[0]->countCap);
    }

    public function testOnlyAnItemThatMarksItsAreaCarriesTheMarkToTheSidebar(): void
    {
        $items = $this->overview(
            null,
            new NavigationItem('overview', 'a', 'plus', 'r1', $this->counter(3), marksArea: true),
            new NavigationItem('overview', 'b', 'plus', 'r2', $this->counter(3)),
        );

        self::assertSame([true, false], array_map(static fn (NavigationEntry $entry): bool => $entry->marksArea, $items));
        self::assertFalse(new NavigationEntry('a', 'plus', 'r1', 3, false)->marksArea, 'An entry marks its area only when told to.');
    }

    public function testACapOfOneIsAllowedAndBelowItRefused(): void
    {
        self::assertSame(1, new NavigationItem('overview', 'a', 'plus', 'r1', countCap: 1)->countCap);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A count cap is a positive number.');

        new NavigationItem('overview', 'a', 'plus', 'r1', countCap: 0);
    }

    public function testIconOutsideTheLockedSetIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new NavigationItem('overview', 'a', 'no-such-icon', 'r1');
    }

    public function testRouteAndLabelAreRequired(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new NavigationItem('overview', '', 'plus', 'r1');
    }

    public function testRouteIsRequired(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new NavigationItem('overview', 'a', 'plus', '');
    }

    public function testCoreProvidesSixBusinessAreasSettingsAndProfile(): void
    {
        $areas = iterator_to_array(new CoreNavigationAreas()->areas(), false);

        self::assertSame(['overview', 'sales', 'purchasing', 'money', 'contacts', 'projects', 'settings', 'profile'], array_map(static fn (NavigationArea $a): string => $a->id, $areas));
        self::assertSame(['layout-grid', 'trending-up', 'shopping-bag', 'landmark', 'users', 'square-kanban', 'settings', 'user'], array_map(static fn (NavigationArea $a): string => $a->icon, $areas));
        self::assertSame([10, 20, 30, 40, 50, 60, 1000, 2000], array_map(static fn (NavigationArea $a): int => $a->weight, $areas));
        self::assertSame('nav.area.sales', $areas[1]->labelKey);
        self::assertSame('nav.area_caption.sales', $areas[1]->captionKey);
    }

    public function testSalesSettingsAndProfileHaveSeveralSectionsAndTheOtherAreasOne(): void
    {
        $sections = array_map(
            static fn (NavigationArea $a): array => array_map(static fn (NavigationSection $s): string => $s->key.' '.$s->labelKey, $a->sections),
            iterator_to_array(new CoreNavigationAreas()->areas(), false),
        );

        self::assertSame([
            ['main nav.area.overview'],
            ['selling nav.section.selling', 'billing nav.section.billing'],
            ['main nav.area.purchasing'],
            ['main nav.area.money'],
            ['main nav.area.contacts'],
            ['main nav.area.projects'],
            ['firm nav.section.firm', 'connections nav.section.connections'],
            ['profile nav.section.profile', 'security nav.section.security', 'notifications nav.section.notifications', 'connections nav.section.connections'],
        ], $sections);
        $weights = array_map(
            static fn (NavigationArea $a): array => array_map(static fn (NavigationSection $s): int => $s->weight, $a->sections),
            iterator_to_array(new CoreNavigationAreas()->areas(), false),
        );
        self::assertSame([[100], [10, 20], [100], [100], [100], [100], [10, 20], [10, 20, 30, 40]], $weights);
    }

    public function testAnAreaOrdersItsSectionsByWeightThenTheOrderGiven(): void
    {
        $area = new NavigationArea('sales', 'l', 'c', 'plus', sections: [
            new NavigationSection('late', 'L', 50),
            new NavigationSection('tie_a', 'A'),
            new NavigationSection('early', 'E', 5),
            new NavigationSection('tie_b', 'B'),
        ]);

        self::assertSame(['early', 'late', 'tie_a', 'tie_b'], array_map(static fn (NavigationSection $s): string => $s->key, $area->sections));
    }

    public function testASectionWeighsAHundredByDefault(): void
    {
        self::assertSame(100, new NavigationSection('billing', 'l')->weight);
    }

    public function testAnAreaDefiningASectionTwiceIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Navigation area "sales" defines a section twice.');

        new NavigationArea('sales', 'l', 'c', 'plus', sections: [new NavigationSection('a', 'A'), new NavigationSection('a', 'B')]);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function malformedSections(): iterable
    {
        yield 'uppercase key' => ['Billing', 'l'];
        yield 'key with trailing junk' => ['billing-x', 'l'];
        yield 'no label' => ['billing', ''];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedSections')]
    public function testAMalformedSectionIsRefused(string $key, string $label): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new NavigationSection($key, $label);
    }

    public function testAMalformedAreaIdIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new NavigationItem('Not An Id', 'a', 'plus', 'r1');
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

        new NavigationItem('sales-x', 'a', 'plus', 'r1');
    }

    public function testAnAreaWithAnIconOutsideTheLockedSetIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new NavigationArea('sales', 'l', 'c', 'no-such-icon');
    }

    public function testItemsAreGroupedByArea(): void
    {
        $areas = $this->areaRegistry(
            new NavigationItem('money', 'bank', 'landmark', 'r_bank'),
            new NavigationItem('purchasing', 'costs', 'file-text', 'r_costs'),
            new NavigationItem('sales', 'invoices', 'receipt-text', 'r_inv'),
            new NavigationItem('overview', 'dashboard', 'layout-grid', 'r_dash'),
            new NavigationItem('overview', 'notifications', 'inbox', 'r_notif'),
            new NavigationItem('settings', 'people', 'user', 'r_people'),
        )->areas('r_costs', true);

        self::assertSame(['overview', 'sales', 'purchasing', 'money', 'settings'], array_map(static fn (array $a): string => $a['area']->id, $areas));
        self::assertSame(['main'], array_map(static fn (array $s): string => $s['key'], $areas[0]['sections']));
        self::assertSame(['dashboard', 'notifications'], self::labels($areas[0]['sections'][0]['items']));
        self::assertSame(['costs'], self::labels($areas[2]['sections'][0]['items']));
        self::assertSame([false, false, true, false, false], array_map(static fn (array $a): bool => $a['current'], $areas));
    }

    public function testItemsAreListedInTheSectionTheyNameInTheOrderOfTheAreasSections(): void
    {
        $areas = $this->areaRegistry(
            new NavigationItem('sales', 'invoices', 'receipt-text', 'r_inv', weight: 10, section: CoreNavigationAreas::SECTION_BILLING),
            new NavigationItem('sales', 'documents', 'file-text', 'r_doc', weight: 30, section: CoreNavigationAreas::SECTION_SELLING),
        )->areas(null, true);

        self::assertSame(['selling', 'billing'], array_map(static fn (array $s): string => $s['key'], $areas[0]['sections']));
        self::assertSame(['nav.section.selling', 'nav.section.billing'], array_map(static fn (array $s): string => $s['labelKey'], $areas[0]['sections']));
        self::assertSame([['documents'], ['invoices']], array_map(static fn (array $s): array => self::labels($s['items']), $areas[0]['sections']));
    }

    public function testAnItemNamingAnUnknownOrNoSectionGoesToTheAreasLastSection(): void
    {
        $areas = $this->areaRegistry(
            new NavigationItem('sales', 'plugin', 'puzzle', 'r_plugin', section: 'nowhere'),
            new NavigationItem('sales', 'unnamed', 'puzzle', 'r_unnamed'),
            new NavigationItem('sales', 'documents', 'file-text', 'r_doc', section: CoreNavigationAreas::SECTION_SELLING),
            new NavigationItem('overview', 'elsewhere', 'puzzle', 'r_else', section: CoreNavigationAreas::SECTION_BILLING),
        )->areas(null, true);

        self::assertSame([['documents'], ['plugin', 'unnamed']], array_map(static fn (array $s): array => self::labels($s['items']), $areas[1]['sections']));
        self::assertSame(['billing'], array_map(static fn (array $s): string => $s['key'], array_slice($areas[1]['sections'], 1)));
        self::assertSame([['elsewhere']], array_map(static fn (array $s): array => self::labels($s['items']), $areas[0]['sections']));
    }

    public function testHeadingsShowFromSevenItemsOfAnAreaWithMoreThanOneSection(): void
    {
        $sales = static fn (int $count): array => array_map(
            static fn (int $i): NavigationItem => new NavigationItem('sales', 'i'.$i, 'plus', 'r'.$i, section: 0 === $i ? CoreNavigationAreas::SECTION_SELLING : CoreNavigationAreas::SECTION_BILLING),
            range(0, $count - 1),
        );
        $overview = array_map(static fn (int $i): NavigationItem => new NavigationItem('overview', 'o'.$i, 'plus', 'o'.$i), range(0, 7));

        self::assertFalse($this->areaRegistry(...$sales(6))->areas(null, true)[0]['headings']);
        self::assertTrue($this->areaRegistry(...$sales(7))->areas(null, true)[0]['headings']);
        self::assertFalse($this->areaRegistry(...$overview)->areas(null, true)[0]['headings'], 'One section never gets a heading.');
        $onlyBilling = array_map(static fn (int $i): NavigationItem => new NavigationItem('sales', 'b'.$i, 'plus', 'b'.$i, section: CoreNavigationAreas::SECTION_BILLING), range(0, 7));
        self::assertFalse($this->areaRegistry(...$onlyBilling)->areas(null, true)[0]['headings'], 'Empty sections are left out, so one filled section gets no heading.');
        self::assertCount(1, $this->areaRegistry(...$onlyBilling)->areas(null, true)[0]['sections']);
    }

    public function testAreasWithoutItemsAreLeftOut(): void
    {
        $areas = $this->areaRegistry(new NavigationItem('overview', 'shared', 'share-2', 'r1'))->areas(null, true);

        self::assertSame(['overview'], array_map(static fn (array $a): string => $a['area']->id, $areas));
        self::assertFalse($areas[0]['current']);
    }

    public function testAreasComeByWeightWithSettingsAlwaysLast(): void
    {
        $registry = new NavigationRegistry(
            [$this->provider(
                new NavigationItem('settings', 's', 'settings', 'r1'),
                new NavigationItem('late', 'b', 'puzzle', 'r2'),
                new NavigationItem('early', 'a', 'puzzle', 'r3'),
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
            [$this->provider(...array_map(static fn (string $id): NavigationItem => new NavigationItem($id, $id, 'puzzle', 'r_'.$id), ['c', 'a', 'b']))],
            [$this->areas(new NavigationArea('b', 'l', 'c', 'puzzle'), new NavigationArea('c', 'l', 'c', 'puzzle')), $this->areas(new NavigationArea('a', 'l', 'c', 'puzzle'))],
        );

        self::assertSame(['b', 'c', 'a'], array_map(static fn (array $a): string => $a['area']->id, $registry->areas(null, true)));
    }

    public function testAnItemInAnUnregisteredAreaFailsLoudly(): void
    {
        $registry = $this->areaRegistry(new NavigationItem('nowhere', 'orphan', 'puzzle', 'r1'));

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
        $registry = $this->areaRegistry(new NavigationItem('overview', 'a', 'inbox', 'r1', $counter));

        self::assertNull($registry->areas(null, false)[0]['sections'][0]['items'][0]->count);
        self::assertSame(0, $counter->calls);
        self::assertSame(1, $registry->areas(null, true)[0]['sections'][0]['items'][0]->count);
    }

    public function testCurrentIsTheItemWhoseOwnRouteThePageIsBeforeAnyPrefix(): void
    {
        $registry = $this->registry(
            new NavigationItem('projects', 'projects', 'columns-3', 'app_projects', routePrefixes: ['app_project', 'app_task']),
            new NavigationItem('projects', 'mine', 'square-check-big', 'app_project_mine'),
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

        self::assertSame('inbox', $this->registry(new NavigationItem('overview', 'inbox', 'inbox', 'app_inbox', $count))->current('app_inbox')?->labelKey);
    }

    /**
     * @param list<NavigationEntry> $items
     *
     * @return list<string>
     */
    private static function labels(array $items): array
    {
        return array_map(static fn (NavigationEntry $e): string => $e->labelKey, $items);
    }

    /**
     * The items of the overview area, its one section.
     *
     * @return list<NavigationEntry>
     */
    private function overview(?string $currentRoute, NavigationItem ...$items): array
    {
        return $this->areaRegistry(...$items)->areas($currentRoute, true)[0]['sections'][0]['items'];
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
