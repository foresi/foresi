<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Navigation;

/**
 * The areas every installation has. They follow the APQC process skeleton,
 * named in plain business words. An area without items is not shown, so a
 * guest sees only the areas their items fall into.
 */
final readonly class CoreNavigationAreas implements NavigationAreaProvider
{
    public const string OVERVIEW = 'overview';
    public const string SALES = 'sales';
    public const string PURCHASING = 'purchasing';
    public const string MONEY = 'money';
    public const string CONTACTS = 'contacts';
    public const string PROJECTS = 'projects';

    public function areas(): iterable
    {
        $icons = [self::OVERVIEW => 'layout-grid', self::SALES => 'trending-up', self::PURCHASING => 'shopping-bag', self::MONEY => 'landmark', self::CONTACTS => 'users', self::PROJECTS => 'square-kanban'];
        $weight = 0;
        foreach ($icons as $id => $icon) {
            $weight += 10;

            yield new NavigationArea($id, 'nav.area.'.$id, 'nav.area_caption.'.$id, $icon, $weight);
        }

        yield new NavigationArea(NavigationArea::SETTINGS, 'nav.area.settings', 'nav.area_caption.settings', 'settings', 1000);
    }
}
