<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\View;

use Foresi\Shared\Domain\View\Placement;
use Foresi\Shared\Domain\View\SavedView;
use Foresi\Shared\Domain\View\Visibility;

/**
 * The views a person sees on a list, in order (spec D7): All first, then the shared
 * views in their one order, then the person's private ones. A view the module ships
 * and app:views:sync has not stored yet shows as declared, so a list never lacks its
 * defaults. A stored filter the list no longer accepts (a field gone with a module)
 * leaves its view out rather than showing what it does not say.
 */
final readonly class ListViews
{
    public function __construct(private SavedViewStore $store)
    {
    }

    /**
     * @return list<ListView>
     */
    public function of(ListDefinition $list, ?string $userId): array
    {
        $views = [];
        $stored = [];
        foreach ($this->store->ofList($list->key) as $saved) {
            if (null !== $saved->systemKey()) {
                $stored[$saved->systemKey()] = true;
            }
            $view = $this->visible($saved, $list, $userId);
            if ($view instanceof ListView) {
                $views[] = [$view, $saved->visibility(), $saved->position()];
            }
        }
        foreach ($list->defaults as $default) {
            if (!isset($stored[$list->systemKey($default)])) {
                $views[] = [new ListView($default->slug, $default->name, true, $default->filter, $default->showCount, $default->placement, slug: $default->slug), Visibility::Shared, $default->position];
            }
        }
        usort($views, static fn (array $a, array $b): int => [$a[0]->isAll() ? 0 : 1, Visibility::Shared === $a[1] ? 0 : 1, $a[2]] <=> [$b[0]->isAll() ? 0 : 1, Visibility::Shared === $b[1] ? 0 : 1, $b[2]]);

        return array_map(static fn (array $view): ListView => $view[0], $views);
    }

    /**
     * The view a list's address names, or its All when it names none it has.
     *
     * @param list<ListView> $views
     */
    public static function chosen(array $views, string $key): ListView
    {
        foreach ($views as $view) {
            if ($view->key === $key) {
                return $view;
            }
        }

        return $views[0] ?? throw new \LogicException('A list has its All view.');
    }

    /**
     * The tabs of a list, All among them, and the rest for its "More views" menu: at
     * most MAX_PRIMARY tabs, the views past them moved to the menu.
     *
     * @param list<ListView> $views
     *
     * @return array{list<ListView>, list<ListView>}
     */
    public static function placed(array $views): array
    {
        $primary = [];
        $overflow = [];
        foreach ($views as $view) {
            if (($view->isAll() || Placement::Primary === $view->placement) && \count($primary) < self::MAX_PRIMARY) {
                $primary[] = $view;
            } else {
                $overflow[] = $view;
            }
        }

        return [$primary, $overflow];
    }

    /** At most this many tabs, All included (spec D7). */
    public const int MAX_PRIMARY = 6;

    private function visible(SavedView $saved, ListDefinition $list, ?string $userId): ?ListView
    {
        if ($saved->isHidden() || (Visibility::Private === $saved->visibility() && $saved->ownerId() !== $userId)) {
            return null;
        }
        try {
            $filter = ViewFilter::parse($saved->filters(), $list);
        } catch (InvalidFilter) {
            return null;
        }
        $slug = null === $saved->systemKey() ? null : substr($saved->systemKey(), \strlen($list->key) + 1);
        // A shipped view keeps its translated name until someone renames it.
        $translated = null !== $slug && null === $saved->updatedBy();

        return new ListView($slug ?? $saved->id(), $saved->name(), $translated, $filter, $saved->showCount(), $saved->placement(), Visibility::Private === $saved->visibility(), $slug);
    }
}
