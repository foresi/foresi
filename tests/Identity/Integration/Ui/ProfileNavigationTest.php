<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Identity\Integration\Ui;

use Foresi\Identity\Domain\Role;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The pages of the person's own account keep their addresses and show the
 * profile's sidebar, which the rail does not list: the avatar is its way in.
 * The pages other modules add to it are tested with them (ProfileNavigationTest).
 */
final class ProfileNavigationTest extends WebTestCase
{
    private KernelBrowser $client;
    private SignedInBrowser $browser;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'));
    }

    /**
     * @return iterable<string, array{string, Role}>
     */
    public static function accountPages(): iterable
    {
        yield 'profile' => ['/account/profile', Role::Owner];
        yield 'api' => ['/settings/api', Role::Owner];
        yield 'connected apps' => ['/connected-apps', Role::Guest];
    }

    #[DataProvider('accountPages')]
    public function testAnAccountPageShowsTheProfileSidebar(string $url, Role $role): void
    {
        $this->browser->seed('en', $role);
        $this->browser->signIn();

        $crawler = $this->client->request('GET', $url);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-nav-area="profile"][data-shown]'));
        self::assertCount(0, $crawler->filter('a[data-nav-area="profile"]'), 'The rail has no entry for the profile.');
    }

    public function testTheStaffSeeEveryConnectionAndAGuestOnlyTheirApps(): void
    {
        $this->browser->seed('en', Role::Owner);
        $this->browser->signIn();
        $links = $this->links('/account/profile');
        foreach (['/account/profile', '/account/two-factor', '/settings/api'] as $href) {
            self::assertContains($href, $links);
        }
        self::assertNotContains('/connected-apps', $links);
    }

    public function testAGuestSeesTheirOwnPagesOnly(): void
    {
        $this->browser->seed('en', Role::Guest);
        $this->browser->signIn();
        $links = $this->links('/account/profile');
        self::assertContains('/connected-apps', $links);
        foreach (['/account/calendar', '/account/inbox', '/settings/api'] as $href) {
            self::assertNotContains($href, $links);
        }
    }

    /**
     * @return list<string>
     */
    private function links(string $url): array
    {
        $crawler = $this->client->request('GET', $url);

        return $crawler->filter('[data-nav-area="profile"][data-shown] a')->each(static fn ($link): string => (string) $link->attr('href'));
    }
}
