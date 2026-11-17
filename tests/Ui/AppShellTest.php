<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Ui;

use App\Tenancy\Application\TenantContext;
use App\Tests\Identity\Support\IdentityFixtures;
use App\Tests\Identity\Support\SignedInBrowser;
use App\Ui\Navigation\NavigationEntry;
use App\Ui\Navigation\NavigationRegistry;
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
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'), self::getContainer()->get(TenantContext::class));
    }

    public function testShellShowsTheActiveOrganisationAndTheSignedInUser(): void
    {
        $this->browser->seed(['Example Company s.r.o.']);
        $this->browser->signIn();

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#rail [data-organisation]', 'Example Company s.r.o.');
        self::assertSelectorTextContains('[data-controller="dropdown"]', 'Example Company');
        self::assertSelectorTextContains('[data-controller="dropdown"]', IdentityFixtures::EMAIL);
        self::assertSelectorCount(1, 'main h1');
        self::assertSelectorTextContains('main h1', 'Dashboard');
    }

    public function testTheOrganisationNameFollowsTheChosenOrganisation(): void
    {
        [, $second] = $this->browser->seed(['Tenant A', 'Tenant B']);
        $this->browser->signIn();
        $this->client->getRequest()->getSession()->set('active_tenant', $second->toString());
        $this->client->getRequest()->getSession()->save();

        $this->client->request('GET', '/');

        self::assertSelectorTextContains('#rail [data-organisation]', 'Tenant B');
        self::assertSelectorTextNotContains('#rail [data-organisation]', 'Tenant A');
        self::assertStringContainsString('Tenant B', $this->client->getCrawler()->filter('#user-menu-button')->text());
    }

    public function testTheNavigationItemOfTheCurrentRouteIsMarked(): void
    {
        $this->browser->seed(['Tenant A']);
        $this->browser->signIn();

        $crawler = $this->client->request('GET', '/');

        self::assertSelectorCount(1, '#sidebar [aria-current="page"]');
        $current = $crawler->filter('#sidebar [aria-current="page"]');
        self::assertSame('/', $current->attr('href'));
        self::assertSelectorTextContains('#sidebar [aria-current="page"]', 'Dashboard');
    }

    public function testTheRailListsTheAreasWithSettingsAtTheBottomAndMarksTheCurrentOne(): void
    {
        $this->browser->seed(['Tenant A']);
        $this->browser->signIn();

        $crawler = $this->client->request('GET', '/');

        $rail = $crawler->filter('#rail');
        self::assertCount(1, $rail);
        self::assertNotNull($rail->attr('data-turbo-permanent'));
        $areas = $rail->filter('a[data-nav-area]')->each(static fn ($a): string => (string) $a->attr('data-nav-area'));
        self::assertSame(['overview', 'sales', 'purchasing', 'money', 'contacts', 'projects', 'extensions', 'settings'], $areas);
        self::assertSame(['overview'], $rail->filter('a[aria-current="true"]')->each(static fn ($a): string => (string) $a->attr('data-nav-area')));
        self::assertSame('/', $rail->filter('a[data-nav-area="overview"]')->attr('href'));
        self::assertSame('Overview', trim($rail->filter('a[data-nav-area="overview"]')->text()));
        // Search, settings and the account follow the spacer, in that order.
        $tail = $rail->filter('[data-controller="command-palette"], a[data-nav-area="settings"], #user-menu-button')->each(static fn ($n): string => $n->nodeName());
        self::assertSame(['div', 'a', 'button'], $tail);
    }

    public function testTheSidebarShowsTheAreaOfThePageWithItsTitleAndCaption(): void
    {
        $this->browser->seed(['Tenant A']);
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
        // One group: no group heading; every area is in the drawer, so the other areas are there too.
        self::assertCount(0, $shown->filter('h3'));
        self::assertCount(8, $sidebar->filter('section[data-nav-area]'));
    }

    public function testOnlyTheContentPanelTakesPartInTheViewTransition(): void
    {
        $this->browser->seed(['Tenant A']);
        $this->browser->signIn();

        $crawler = $this->client->request('GET', '/');

        self::assertSame('same-origin', $crawler->filter('meta[name="view-transition"]')->attr('content'));
        self::assertStringContainsString('[view-transition-name:content]', (string) $crawler->filter('main')->attr('class'));
        self::assertSame(1, substr_count((string) $this->client->getResponse()->getContent(), 'view-transition-name'));
    }

    public function testNoUnreadDotWithoutUnreadNotifications(): void
    {
        $this->browser->seed(['Tenant A']);
        $this->browser->signIn();

        $this->client->request('GET', '/');

        self::assertSelectorNotExists('#rail [data-nav-unread]');
    }

    public function testEveryNavigationItemSitsInExactlyOneArea(): void
    {
        $this->browser->seed(['Tenant A']);
        $this->browser->signIn();
        $this->client->request('GET', '/');

        $registry = self::getContainer()->get(NavigationRegistry::class);
        $all = self::entries($registry->groups('app_dashboard', false));
        $areas = $registry->areas('app_dashboard', false);
        $inAreas = array_merge(...array_map(static fn (array $area): array => self::entries($area['groups']), $areas));

        self::assertGreaterThan(15, \count($all), 'The owner should see the full navigation.');
        sort($all);
        sort($inAreas);
        self::assertSame($all, $inAreas);
        self::assertSame(['overview', 'sales', 'purchasing', 'money', 'contacts', 'projects', 'extensions', 'settings'], array_map(static fn (array $a): string => $a['area']->id, $areas));
    }

    public function testARegisteredProviderItemAppearsInTheExtensionsGroupWithItsCount(): void
    {
        $this->browser->seed(['Tenant A']);
        $this->browser->signIn();

        $crawler = $this->client->request('GET', '/');

        $area = $crawler->filter('#sidebar [data-nav-area="extensions"]');
        self::assertCount(1, $area);
        self::assertSame('Extensions', $area->filter('h2')->text());
        $group = $area->filter('[data-nav-group="extensions"]');
        self::assertCount(1, $group);
        self::assertStringContainsString('Pergola configurator', $group->text());
        self::assertSame('4', trim($group->filter('[data-nav-count]')->text()));
        self::assertSame('/organisation/choose', $group->filter('a')->attr('href'));
    }

    public function testNoCountIsComputedWithoutAnActiveOrganisation(): void
    {
        // A user without any organisation has no tenant: the shell still renders, without counts.
        $this->browser->seed([]);
        $this->browser->signIn();

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#sidebar [data-nav-count]');
        self::assertSelectorExists('#sidebar [data-nav-group="extensions"]');
    }

    public function testRailHasASearchOpeningTheCommandPaletteThatListsTheNavigation(): void
    {
        $this->browser->seed(['Tenant A']);
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
        $this->browser->seed(['Tenant A']);
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

    public function testUserMenuListsProfileNotificationsApiLanguageOrganisationAndLogout(): void
    {
        $this->browser->seed(['Tenant A', 'Tenant B']);
        $this->browser->signIn();
        $this->client->request('GET', '/organisation/choose');
        $this->client->submit($this->client->getCrawler()->filter('form[action*="/organisation/switch/"]')->first()->form());

        $crawler = $this->client->request('GET', '/');

        $menu = $crawler->filter('[data-controller="dropdown"] [role="menu"]');
        self::assertCount(1, $menu);
        self::assertTrue($menu->matches('[hidden]'));
        self::assertCount(6, $menu->filter('[role="menuitem"]'));
        self::assertSelectorExists('[role="menu"] a[href="/account/notifications"]');
        self::assertSelectorExists('[role="menu"] form[action="/account/language"] input[name="_token"]');
        self::assertSelectorExists('[role="menu"] form[action="/logout"][method="post"] input[name="_csrf_token"]');
        self::assertSelectorExists('[role="menu"] a[href="/organisation/choose"]');
        self::assertSame('menu', $crawler->filter('[data-dropdown-target="button"]')->attr('aria-haspopup'));
    }

    public function testSwitchOrganisationEntryIsHiddenForASingleOrganisation(): void
    {
        $this->browser->seed(['Tenant A']);
        $this->browser->signIn();

        $this->client->request('GET', '/');

        self::assertSelectorNotExists('[role="menu"] a[href="/organisation/choose"]');
        self::assertSelectorNotExists('[role="menu"] a[data-organisation]');
    }

    #[DataProvider('titles')]
    public function testPageTitleIsEscapedExactlyOnce(string $title): void
    {
        $this->browser->seed(['Tenant A']);
        $this->browser->signIn();

        $crawler = $this->client->request('GET', '/_test/shell', ['title' => $title]);

        self::assertResponseIsSuccessful();
        self::assertSame($title, $crawler->filter('main h1')->text());
        self::assertSame($title, $crawler->filter('title')->text());
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
        $this->browser->seed(['Tenant A']);
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
        $this->browser->seed(['Secret Tenant']);

        $crawler = $this->client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Foresi');
        self::assertSelectorTextNotContains('body', 'Secret Tenant');
        self::assertSelectorNotExists('#sidebar');
        self::assertSelectorExists('label[for="username"]');
        self::assertSelectorExists('label[for="password"]');
        self::assertSame('username', $crawler->filter('#username')->attr('autocomplete'));
        self::assertSame('current-password', $crawler->filter('#password')->attr('autocomplete'));
        self::assertSelectorCount(1, 'h1');
    }

    public function testLoginErrorIsAnnounced(): void
    {
        $this->browser->seed(['Tenant A']);
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->filter('form button[type="submit"]')->form(['_username' => IdentityFixtures::EMAIL, '_password' => 'wrong']));
        $this->client->followRedirect();

        self::assertSelectorExists('[role="alert"]');
    }

    /**
     * @param list<array{key: string, labelKey: string, items: list<NavigationEntry>}> $groups
     *
     * @return list<string>
     */
    private static function entries(array $groups): array
    {
        return array_merge(...array_map(static fn (array $group): array => array_map(static fn (NavigationEntry $entry): string => $entry->labelKey.' '.$entry->route, $group['items']), $groups));
    }
}
