<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Ui;

use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatStatus;
use Foresi\Tests\Identity\Support\IdentityFixtures;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Ui\Navigation\NavigationEntry;
use Foresi\Ui\Navigation\NavigationRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AppShellTest extends WebTestCase
{
    private KernelBrowser $client;
    private SignedInBrowser $browser;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'));
    }

    public function testShellShowsTheActiveOrganisationAndTheSignedInUser(): void
    {
        $this->browser->seed();
        $this->firm('Example Company s.r.o.');
        $this->browser->signIn();

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#rail [data-organisation]', 'Example Company s.r.o.');
        self::assertSelectorTextContains('[data-controller="dropdown"]', 'Example Company');
        self::assertSelectorTextContains('[data-controller="dropdown"]', IdentityFixtures::EMAIL);
        self::assertSelectorCount(1, 'main h1');
        self::assertSelectorTextContains('main h1', 'Dashboard');
    }

    public function testEveryPageCarriesTheForesiMarkAsItsIcon(): void
    {
        $crawler = $this->client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        $icon = $crawler->filter('head link[rel="icon"]');
        self::assertCount(1, $icon);
        self::assertSame('image/svg+xml', $icon->attr('type'));
        self::assertStringStartsWith('data:image/svg+xml,%3Csvg', (string) $icon->attr('href'));
    }

    public function testTheNavigationItemOfTheCurrentRouteIsMarked(): void
    {
        $this->browser->seed();
        $this->browser->signIn();

        $crawler = $this->client->request('GET', '/');

        self::assertSelectorCount(1, '#sidebar [aria-current="page"]');
        $current = $crawler->filter('#sidebar [aria-current="page"]');
        self::assertSame('/', $current->attr('href'));
        self::assertSelectorTextContains('#sidebar [aria-current="page"]', 'Dashboard');
    }

    public function testTheRailListsTheAreasWithSettingsAtTheBottomAndMarksTheCurrentOne(): void
    {
        $this->browser->seed();
        $this->browser->signIn();

        $crawler = $this->client->request('GET', '/');

        $rail = $crawler->filter('#rail');
        self::assertCount(1, $rail);
        self::assertNotNull($rail->attr('data-turbo-permanent'));
        $areas = $rail->filter('a[data-nav-area]')->each(static fn ($a): string => (string) $a->attr('data-nav-area'));
        // The installed modules decide which areas there are; their order is fixed, the overview
        // first, the contacts always and the settings last.
        self::assertSame(array_values(array_intersect(['overview', 'sales', 'purchasing', 'money', 'contacts', 'projects', 'extensions', 'settings'], $areas)), $areas);
        self::assertSame(['overview', 'contacts', 'settings'], array_values(array_intersect($areas, ['overview', 'contacts', 'settings'])));
        self::assertSame('settings', end($areas));
        self::assertSame(['overview'], $rail->filter('a[aria-current="true"]')->each(static fn ($a): string => (string) $a->attr('data-nav-area')));
        self::assertSame('/', $rail->filter('a[data-nav-area="overview"]')->attr('href'));
        self::assertSame('Overview', trim($rail->filter('a[data-nav-area="overview"]')->text()));
        // Search, settings and the account follow the spacer, in that order.
        $tail = $rail->filter('[data-controller="command-palette"], a[data-nav-area="settings"], #user-menu-button')->each(static fn ($n): string => $n->nodeName());
        self::assertSame(['div', 'a', 'button'], $tail);
    }

    public function testTheSidebarShowsTheAreaOfThePageWithItsTitleAndCaption(): void
    {
        $this->browser->seed();
        $this->browser->signIn();

        $crawler = $this->client->request('GET', '/settings/people');

        self::assertResponseIsSuccessful();
        $sidebar = $crawler->filter('#sidebar');
        self::assertNotNull($sidebar->attr('data-turbo-permanent'));
        $shown = $sidebar->filter('[data-nav-area][data-shown]');
        self::assertCount(1, $shown);
        self::assertSame('settings', $shown->attr('data-nav-area'));
        self::assertSame('Settings', $shown->filter('h2')->text());
        self::assertSame('The firm, people and connections', $shown->filter('h2 + p')->text());
        self::assertSame('settings', $crawler->filter('#rail a[aria-current="true"]')->attr('data-nav-area'));
        self::assertSame('/settings/people', $sidebar->filter('[aria-current="page"]')->attr('href'));
        // From seven items in two sections the section headings show; every area is in the drawer, so the other areas are there too.
        self::assertSame($shown->filter('li')->count() >= 7 ? ['Firm', 'Connections'] : [], $shown->filter('h3')->each(static fn ($h): string => trim($h->text())));
        // The rail does not draw the profile area (the user menu does), but the sidebar and drawer hold it.
        self::assertCount($crawler->filter('#rail a[data-nav-area]')->count() + 1, $sidebar->filter('section[data-nav-area]'));
    }

    public function testOnlyTheContentPanelTakesPartInTheViewTransition(): void
    {
        $this->browser->seed();
        $this->browser->signIn();

        $crawler = $this->client->request('GET', '/');

        self::assertSame('same-origin', $crawler->filter('meta[name="view-transition"]')->attr('content'));
        self::assertStringContainsString('[view-transition-name:content]', (string) $crawler->filter('main')->attr('class'));
        self::assertSame(1, substr_count((string) $this->client->getResponse()->getContent(), 'view-transition-name'));
    }

    public function testNoUnreadDotWithoutUnreadNotifications(): void
    {
        $this->browser->seed();
        $this->browser->signIn();

        $this->client->request('GET', '/');

        self::assertSelectorNotExists('#rail [data-nav-unread]');
    }

    public function testEveryNavigationItemSitsInExactlyOneArea(): void
    {
        $this->browser->seed();
        $this->browser->signIn();
        $this->client->request('GET', '/');

        $registry = self::getContainer()->get(NavigationRegistry::class);
        $areas = $registry->areas('app_dashboard', false);
        $inAreas = array_merge(...array_map(static fn (array $area): array => self::entries($area['sections']), $areas));

        // The installed modules decide how many items and areas there are; the order of the areas is fixed.
        self::assertNotEmpty($inAreas, 'The owner should see the navigation.');
        self::assertSame($inAreas, array_values(array_unique($inAreas)), 'No item is listed twice.');
        $ids = array_map(static fn (array $a): string => $a['area']->id, $areas);
        self::assertSame(array_values(array_intersect(['overview', 'sales', 'purchasing', 'money', 'contacts', 'projects', 'extensions', 'settings', 'profile'], $ids)), $ids);
        self::assertSame('profile', end($ids));
    }

    public function testSalesShowsItsSectionHeadingsAndASmallAreaStaysFlat(): void
    {
        $this->browser->seed();
        $this->browser->signIn();

        $crawler = $this->client->request('GET', '/');

        $sales = $crawler->filter('#sidebar [data-nav-area="sales"]');
        if (2 > $sales->filter('[data-nav-section]')->count()) {
            self::markTestSkipped('Needs the Documents, Catalog and Invoicing modules installed.');
        }
        self::assertSame(['selling', 'billing'], $sales->filter('[data-nav-section]')->each(static fn ($s): string => (string) $s->attr('data-nav-section')));
        self::assertSame(['Selling', 'Billing'], $sales->filter('[data-nav-section] > h3')->each(static fn ($h): string => trim($h->text())));
        self::assertSame(['/sales/deals', '/documents', '/catalog/products'], $sales->filter('[data-nav-section="selling"] a')->each(static fn ($a): string => (string) $a->attr('href')));
        self::assertSame('nav-section-sales-billing', $sales->filter('[data-nav-section="billing"]')->attr('aria-labelledby'));
        self::assertCount(0, $crawler->filter('#sidebar [data-nav-area="projects"] h3'));
        self::assertNull($crawler->filter('#sidebar [data-nav-area="projects"] [data-nav-section]')->attr('aria-labelledby'));
        // The command palette groups the items of an area with headings by the same sections.
        $groups = $crawler->filter('[data-controller="command-palette"] ul[role="group"]')->each(static fn ($g): string => trim($g->filter('li[role="presentation"]')->first()->text()));
        self::assertSame(['Go to', 'Selling', 'Billing', 'Firm', 'Connections'], $groups);
    }

    public function testARegisteredProviderItemAppearsInTheExtensionsGroupWithItsCount(): void
    {
        $this->browser->seed();
        $this->browser->signIn();

        $crawler = $this->client->request('GET', '/');

        $area = $crawler->filter('#sidebar [data-nav-area="extensions"]');
        self::assertCount(1, $area);
        self::assertSame('Extensions', $area->filter('h2')->text());
        $group = $area->filter('[data-nav-section]');
        self::assertCount(1, $group);
        self::assertStringContainsString('Pergola configurator', $group->text());
        self::assertSame('4', trim($group->filter('[data-nav-count]')->text()));
        self::assertSame('/settings/people/invitations', $group->filter('a')->attr('href'));
    }

    public function testNoCountIsComputedWithoutAnActiveOrganisation(): void
    {
        // A user without a membership has no active organisation: the shell still renders, without counts.
        $this->browser->seed(role: null);
        $this->browser->signIn();

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#sidebar [data-nav-count]');
        self::assertSelectorExists('#sidebar [data-nav-section]');
    }

    public function testRailHasASearchOpeningTheCommandPaletteThatListsTheNavigation(): void
    {
        $this->browser->seed();
        $this->browser->signIn();

        $crawler = $this->client->request('GET', '/');

        self::assertSelectorExists('#rail [data-controller="command-palette"] button[data-action="command-palette#open"]');
        self::assertSelectorTextContains('#rail [data-controller="command-palette"] button[data-action="command-palette#open"]', 'Search');
        $options = $crawler->filter('[data-controller="command-palette"] [role="option"]')->each(static fn ($o): string => $o->text());
        self::assertNotEmpty(array_filter($options, static fn (string $t): bool => str_contains($t, 'Dashboard')));
        self::assertNotEmpty(array_filter($options, static fn (string $t): bool => str_contains($t, 'Pergola configurator')));
    }

    public function testMobileMenuButtonControlsTheDrawer(): void
    {
        $this->browser->seed();
        $this->browser->signIn();

        $crawler = $this->client->request('GET', '/');

        $button = $crawler->filter('button[data-sidebar-target="toggle"]');
        self::assertCount(1, $button);
        self::assertSame('navigation', $button->attr('aria-controls'));
        self::assertSame('false', $button->attr('aria-expanded'));
        self::assertNotSame('', trim((string) $button->attr('aria-label')));
        // The button sits in the rail, the phone app bar; the overlay holds the sidebar and its own X.
        self::assertSelectorExists('#rail button[data-sidebar-target="toggle"]');
        self::assertSelectorExists('#navigation[data-sidebar-target="panel"] > #sidebar');
        self::assertSelectorExists('#navigation button[data-action="sidebar#close"][aria-label]');
        // One instance of search and of the account menu, so their ids stay unique.
        self::assertSelectorCount(1, '#user-menu-button');
        self::assertSelectorCount(1, '[data-command-palette-target="opener"]');
    }

    public function testUserMenuListsProfileNotificationsCalendarLanguageAndLogout(): void
    {
        $this->browser->seed();
        $this->browser->signIn();

        $crawler = $this->client->request('GET', '/');

        $menu = $crawler->filter('[data-controller="dropdown"] [role="menu"]');
        self::assertCount(1, $menu);
        self::assertTrue($menu->matches('[hidden]'));
        // The profile, the language and the sign-out are always there; the notifications of
        // Work and the calendar of Calendar come with their modules, whose tests check them.
        self::assertGreaterThanOrEqual(3, $menu->filter('[role="menuitem"]')->count());
        // API and MCP live in the settings only.
        self::assertSelectorNotExists('[role="menu"] a[href="/settings/api"]');
        self::assertSelectorExists('[role="menu"] form[action="/account/language"] input[name="_token"]');
        self::assertSelectorExists('[role="menu"] form[action="/logout"][method="post"] input[name="_csrf_token"]');
        self::assertSelectorNotExists('[role="menu"] a[href="/organisation/choose"]');
        self::assertSame('menu', $crawler->filter('[data-dropdown-target="button"]')->attr('aria-haspopup'));
    }

    #[DataProvider('titles')]
    public function testPageTitleIsEscapedExactlyOnce(string $title): void
    {
        $this->browser->seed();
        $this->browser->signIn();

        $crawler = $this->client->request('GET', '/_test/shell', ['title' => $title]);

        self::assertResponseIsSuccessful();
        self::assertSame($title, $crawler->filter('main h1')->text());
        self::assertSame($title.' · Foresi', $crawler->filter('title')->text());
        self::assertStringNotContainsString('&amp;amp;', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('&amp;quot;', (string) $this->client->getResponse()->getContent());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function titles(): iterable
    {
        yield 'ampersand' => ['Novák & syn s.r.o.'];
        yield 'quotes' => ['Firma "ABC" a \'D\''];
        yield 'markup stays text' => ['<b>x</b> & &lt;i&gt;'];
    }

    public function testCrumbsVariableRendersTheBreadcrumbNavigation(): void
    {
        $this->browser->seed();
        $this->browser->signIn();

        $crawler = $this->client->request('GET', '/_test/shell', ['title' => 'Detail']);

        $nav = $crawler->filter('main nav[aria-label]');
        self::assertCount(1, $nav);
        self::assertSame('/contacts', $nav->filter('a')->attr('href'));
        self::assertSame('Contacts', $nav->filter('a')->text());
        self::assertSame('Detail', $nav->filter('[aria-current="page"]')->text());
    }

    public function testLoginPageIsStyledAndOrganisationNeutral(): void
    {
        $this->browser->seed();
        $this->firm('Secret Firm s.r.o.');

        $crawler = $this->client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Foresi');
        self::assertSelectorTextNotContains('body', 'Secret Firm');
        self::assertSelectorNotExists('#sidebar');
        self::assertSelectorExists('label[for="username"]');
        self::assertSelectorExists('label[for="password"]');
        self::assertSame('username', $crawler->filter('#username')->attr('autocomplete'));
        self::assertSame('current-password', $crawler->filter('#password')->attr('autocomplete'));
        self::assertSelectorCount(1, 'h1');
    }

    public function testLoginErrorIsAnnounced(): void
    {
        $this->browser->seed();
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->filter('form button[type="submit"]')->form(['_username' => IdentityFixtures::EMAIL, '_password' => 'wrong']));
        $this->client->followRedirect();

        self::assertSelectorExists('[role="alert"]');
    }

    private function firm(string $name): void
    {
        $em = $this->browser->em();
        $em->persist(new LegalEntity($name, new RegistrationNumber('CZ', '12345679'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, ''));
        $em->flush();
    }

    /**
     * @param list<array{key: string, labelKey: string, items: list<NavigationEntry>}> $sections
     *
     * @return list<string>
     */
    private static function entries(array $sections): array
    {
        return array_merge(...array_map(static fn (array $section): array => array_map(static fn (NavigationEntry $entry): string => $entry->labelKey.' '.$entry->route, $section['items']), $sections));
    }
}
