<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Ui;

use App\Tenancy\Application\TenantContext;
use App\Tests\Identity\Support\IdentityFixtures;
use App\Tests\Identity\Support\SignedInBrowser;
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
        self::assertSelectorTextContains('#sidebar', 'Example Company s.r.o.');
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

        self::assertSelectorTextContains('#sidebar [data-organisation]', 'Tenant B');
        self::assertSelectorTextNotContains('#sidebar [data-organisation]', 'Tenant A');
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

    public function testARegisteredProviderItemAppearsInTheExtensionsGroupWithItsCount(): void
    {
        $this->browser->seed(['Tenant A']);
        $this->browser->signIn();

        $crawler = $this->client->request('GET', '/');

        $group = $crawler->filter('#sidebar [data-nav-group="extensions"]');
        self::assertCount(1, $group);
        self::assertStringContainsString('Extensions', $group->text());
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

    public function testSidebarHasASearchOpeningTheCommandPaletteThatListsTheNavigation(): void
    {
        $this->browser->seed(['Tenant A']);
        $this->browser->signIn();

        $crawler = $this->client->request('GET', '/');

        self::assertSelectorExists('#sidebar [data-controller="command-palette"] button[data-action="command-palette#open"]');
        $options = $crawler->filter('[data-controller="command-palette"] [role="option"]')->each(static fn ($o): string => $o->text());
        self::assertNotEmpty(array_filter($options, static fn (string $t): bool => str_contains($t, 'Dashboard')));
        self::assertNotEmpty(array_filter($options, static fn (string $t): bool => str_contains($t, 'Pergola configurator')));
    }

    public function testMobileMenuButtonControlsTheSidebar(): void
    {
        $this->browser->seed(['Tenant A']);
        $this->browser->signIn();

        $crawler = $this->client->request('GET', '/');

        $button = $crawler->filter('button[data-sidebar-target="toggle"]');
        self::assertCount(1, $button);
        self::assertSame('sidebar', $button->attr('aria-controls'));
        self::assertSame('false', $button->attr('aria-expanded'));
        self::assertNotSame('', trim((string) $button->attr('aria-label')));
        self::assertSelectorExists('#sidebar[data-sidebar-target="panel"]');
    }

    public function testUserMenuListsProfileApiLanguageOrganisationAndLogout(): void
    {
        $this->browser->seed(['Tenant A', 'Tenant B']);
        $this->browser->signIn();
        $this->client->request('GET', '/organisation/choose');
        $this->client->submit($this->client->getCrawler()->filter('form[action*="/organisation/switch/"]')->first()->form());

        $crawler = $this->client->request('GET', '/');

        $menu = $crawler->filter('[data-controller="dropdown"] [role="menu"]');
        self::assertCount(1, $menu);
        self::assertTrue($menu->matches('[hidden]'));
        self::assertCount(5, $menu->filter('[role="menuitem"]'));
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
        self::assertSelectorNotExists('#sidebar a[data-organisation]');
    }

    public function testProductNameComesFromTheParameter(): void
    {
        $this->browser->seed(['Tenant A']);
        $this->browser->signIn();

        $this->client->request('GET', '/');

        self::assertSame('Foresi', self::getContainer()->getParameter('app.product_name'));
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
}
