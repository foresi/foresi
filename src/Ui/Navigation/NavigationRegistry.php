<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Navigation;

use Foresi\Ui\Contract\Navigation\NavigationArea;
use Foresi\Ui\Contract\Navigation\NavigationAreaProvider;
use Foresi\Ui\Contract\Navigation\NavigationItem;
use Foresi\Ui\Contract\Navigation\NavigationItemProvider;
use Foresi\Ui\Contract\Navigation\NavigationSection;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Collects the items of all providers into the navigation structure: the areas,
 * each with the sections it defines and the items in them.
 *
 * @phpstan-type Section array{key: string, labelKey: string, items: list<NavigationEntry>}
 * @phpstan-type Area array{area: NavigationArea, current: bool, headings: bool, sections: list<Section>}
 */
final readonly class NavigationRegistry
{
    /**
     * @param iterable<NavigationItemProvider> $providers
     * @param iterable<NavigationAreaProvider> $areaProviders
     */
    public function __construct(
        #[AutowireIterator(NavigationItemProvider::TAG)]
        private iterable $providers,
        #[AutowireIterator(NavigationArea::TAG)]
        private iterable $areaProviders = [],
    ) {
    }

    /**
     * The areas that have items, by weight then registration order, with the
     * settings area always last. An area is current when one of its items is.
     * Its empty sections are left out, and it shows section headings only from
     * NavigationArea::SECTION_HEADINGS_FROM items and more than one section.
     *
     * @param string|null $currentRoute route of the page being shown
     * @param bool        $member       counts run queries on the instance's data, so they are skipped without an active membership
     *
     * @return list<Area>
     *
     * @throws \LogicException when an item names an area no provider registers, or two providers register one id
     */
    public function areas(?string $currentRoute, bool $member): array
    {
        $areas = [];
        foreach ($this->areaProviders as $provider) {
            foreach ($provider->areas() as $area) {
                if (isset($areas[$area->id])) {
                    throw new \LogicException(sprintf('Navigation area "%s" is registered twice.', $area->id));
                }
                $areas[$area->id] = $area;
            }
        }
        // Sorting is stable, so areas of equal weight keep their registration order.
        uasort($areas, static fn (NavigationArea $a, NavigationArea $b): int => [NavigationArea::SETTINGS === $a->id, $a->weight] <=> [NavigationArea::SETTINGS === $b->id, $b->weight]);

        $byArea = array_fill_keys(array_keys($areas), []);
        foreach ($this->entries($currentRoute, $member) as $entry) {
            if (!isset($byArea[$entry[0]->area])) {
                throw new \LogicException(sprintf('Navigation item "%s" names the area "%s", which no provider registers.', $entry[0]->labelKey, $entry[0]->area));
            }
            $byArea[$entry[0]->area][] = $entry;
        }

        $result = [];
        foreach ($byArea as $id => $entries) {
            if ([] === $entries) {
                continue;
            }
            $sections = $this->sectioned($areas[$id], $entries);
            $result[] = [
                'area' => $areas[$id],
                'current' => array_any($entries, static fn (array $entry): bool => $entry[2]->current),
                'headings' => \count($sections) > 1 && \count($entries) >= NavigationArea::SECTION_HEADINGS_FROM,
                'sections' => $sections,
            ];
        }

        return $result;
    }

    /**
     * The item the page belongs to: the one whose own route it is, or else the first whose prefix
     * covers it. It runs no count, so the page title can ask for it on every page.
     */
    public function current(?string $currentRoute): ?NavigationItem
    {
        $items = $this->items();
        $owned = array_any($items, static fn (NavigationItem $item): bool => $item->route === $currentRoute);

        return array_find($items, fn (NavigationItem $item): bool => $this->isCurrent($item, $currentRoute, $owned));
    }

    /**
     * @return list<NavigationItem>
     */
    private function items(): array
    {
        $items = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->items() as $item) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * Every item with its registration order and its resolved entry.
     *
     * @return list<array{NavigationItem, int, NavigationEntry}>
     */
    private function entries(?string $currentRoute, bool $member): array
    {
        $items = $this->items();

        // A page that is an item of its own is that item's, even where another item's prefix covers it.
        $owned = array_any($items, static fn (NavigationItem $item): bool => $item->route === $currentRoute);
        $entries = [];
        foreach ($items as $order => $item) {
            $entries[] = [$item, $order, new NavigationEntry(
                $item->labelKey,
                $item->icon,
                $item->route,
                $member ? $item->countProvider?->count() : null,
                $this->isCurrent($item, $currentRoute, $owned),
                $item->commandLabelKey,
                $item->commandRoute,
                $item->countCap,
                $item->marksArea,
            )];
        }

        return $entries;
    }

    /**
     * @param list<array{NavigationItem, int, NavigationEntry}> $entries
     *
     * @return list<Section>
     */
    private function sectioned(NavigationArea $area, array $entries): array
    {
        $bySection = array_fill_keys(array_map(static fn (NavigationSection $section): string => $section->key, $area->sections), []);
        foreach ($entries as $entry) {
            $bySection[$area->section($entry[0]->section)->key][] = $entry;
        }

        $sections = [];
        foreach ($area->sections as $section) {
            $items = $bySection[$section->key];
            if ([] === $items) {
                continue;
            }
            usort($items, static fn (array $a, array $b): int => [$a[0]->weight, $a[1]] <=> [$b[0]->weight, $b[1]]);
            $sections[] = [
                'key' => $section->key,
                'labelKey' => $section->labelKey,
                'items' => array_map(static fn (array $entry): NavigationEntry => $entry[2], $items),
            ];
        }

        return $sections;
    }

    private function isCurrent(NavigationItem $item, ?string $currentRoute, bool $owned): bool
    {
        if (null === $currentRoute) {
            return false;
        }
        if ($item->route === $currentRoute) {
            return true;
        }

        return !$owned && array_any($item->routePrefixes, static fn (string $prefix): bool => str_starts_with($currentRoute, $prefix));
    }
}
