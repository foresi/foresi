<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Navigation;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Collects the items of all providers into the sidebar structure.
 *
 * @phpstan-type Group array{key: string, labelKey: string, items: list<NavigationEntry>}
 */
final readonly class NavigationRegistry
{
    public const string TAG = 'app.navigation_item';

    /**
     * @param iterable<NavigationItemProvider> $providers
     */
    public function __construct(
        #[AutowireIterator(self::TAG)]
        private iterable $providers,
    ) {
    }

    /**
     * @param string|null $currentRoute route of the page being shown
     * @param bool        $tenantActive counts run tenant-owned queries, so they are skipped without a tenant
     *
     * @return list<Group>
     */
    public function groups(?string $currentRoute, bool $tenantActive): array
    {
        $byGroup = array_fill_keys(NavigationItem::GROUPS, []);
        $order = 0;
        foreach ($this->providers as $provider) {
            foreach ($provider->items() as $item) {
                $byGroup[$item->groupKey][] = [$item, $order++];
            }
        }

        $groups = [];
        foreach ($byGroup as $key => $items) {
            if ([] === $items) {
                continue;
            }
            usort($items, static fn (array $a, array $b): int => [$a[0]->weight, $a[1]] <=> [$b[0]->weight, $b[1]]);
            $groups[] = [
                'key' => $key,
                'labelKey' => 'nav.group.'.$key,
                'items' => array_map(
                    static fn (array $entry): NavigationEntry => new NavigationEntry(
                        $entry[0]->labelKey,
                        $entry[0]->icon,
                        $entry[0]->route,
                        $tenantActive ? $entry[0]->countProvider?->count() : null,
                        null !== $currentRoute && $entry[0]->route === $currentRoute,
                        $entry[0]->commandLabelKey,
                        $entry[0]->commandRoute,
                    ),
                    $items,
                ),
            ];
        }

        return $groups;
    }
}
