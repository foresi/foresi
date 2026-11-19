<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Contract\Navigation;

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
        yield new NavigationArea(self::OVERVIEW, 'nav.area.overview', 'nav.area_caption.overview', 'layout-grid', 10);
        yield new NavigationArea(self::SALES, 'nav.area.sales', 'nav.area_caption.sales', 'trending-up', 20);
        yield new NavigationArea(self::PURCHASING, 'nav.area.purchasing', 'nav.area_caption.purchasing', 'shopping-bag', 30);
        yield new NavigationArea(self::MONEY, 'nav.area.money', 'nav.area_caption.money', 'landmark', 40);
        yield new NavigationArea(self::CONTACTS, 'nav.area.contacts', 'nav.area_caption.contacts', 'users', 50);
        yield new NavigationArea(self::PROJECTS, 'nav.area.projects', 'nav.area_caption.projects', 'square-kanban', 60);
        yield new NavigationArea(NavigationArea::SETTINGS, 'nav.area.settings', 'nav.area_caption.settings', 'settings', 1000);
    }
}
