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
 *
 * Sales, Settings and Profile have sections; the other areas have one section each.
 * Profile is the person's own account, where every module puts what is theirs
 * alone, such as a calendar feed or a notification setting.
 */
final readonly class CoreNavigationAreas implements NavigationAreaProvider
{
    public const string OVERVIEW = 'overview';
    public const string SALES = 'sales';
    public const string PURCHASING = 'purchasing';
    public const string MONEY = 'money';
    public const string CONTACTS = 'contacts';
    public const string PROJECTS = 'projects';

    /** Sales: winning and keeping the work (deals, documents, products). */
    public const string SECTION_SELLING = 'selling';
    /** Sales: invoicing it. */
    public const string SECTION_BILLING = 'billing';
    /** Settings: the firm and how it works (firm, people, labels, documents). */
    public const string SECTION_FIRM = 'firm';
    /** Settings: connections to other systems (import, API, webhooks). */
    public const string SECTION_CONNECTIONS = 'connections';
    /** Profile: the person themselves (photo, name, language). */
    public const string SECTION_PROFILE = 'profile';
    /** Profile: how they sign in (two-factor authentication). */
    public const string SECTION_SECURITY = 'security';
    /** Profile: what Foresi tells them, and how. */
    public const string SECTION_NOTIFICATIONS = 'notifications';
    // Profile: their own connections (calendar feeds, API tokens, connected apps) use SECTION_CONNECTIONS.

    public function areas(): iterable
    {
        yield new NavigationArea(self::OVERVIEW, 'nav.area.overview', 'nav.area_caption.overview', 'layout-grid', 10);
        yield new NavigationArea(self::SALES, 'nav.area.sales', 'nav.area_caption.sales', 'trending-up', 20, [
            new NavigationSection(self::SECTION_SELLING, 'nav.section.selling', 10),
            new NavigationSection(self::SECTION_BILLING, 'nav.section.billing', 20),
        ]);
        yield new NavigationArea(self::PURCHASING, 'nav.area.purchasing', 'nav.area_caption.purchasing', 'shopping-bag', 30);
        yield new NavigationArea(self::MONEY, 'nav.area.money', 'nav.area_caption.money', 'landmark', 40);
        yield new NavigationArea(self::CONTACTS, 'nav.area.contacts', 'nav.area_caption.contacts', 'users', 50);
        yield new NavigationArea(self::PROJECTS, 'nav.area.projects', 'nav.area_caption.projects', 'square-kanban', 60);
        yield new NavigationArea(NavigationArea::SETTINGS, 'nav.area.settings', 'nav.area_caption.settings', 'settings', 1000, [
            new NavigationSection(self::SECTION_FIRM, 'nav.section.firm', 10),
            new NavigationSection(self::SECTION_CONNECTIONS, 'nav.section.connections', 20),
        ]);
        yield new NavigationArea(NavigationArea::PROFILE, 'nav.area.profile', 'nav.area_caption.profile', 'user', 2000, [
            new NavigationSection(self::SECTION_PROFILE, 'nav.section.profile', 10),
            new NavigationSection(self::SECTION_SECURITY, 'nav.section.security', 20),
            new NavigationSection(self::SECTION_NOTIFICATIONS, 'nav.section.notifications', 30),
            new NavigationSection(self::SECTION_CONNECTIONS, 'nav.section.connections', 40),
        ]);
    }
}
