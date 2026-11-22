<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Identity\Integration\Work;

use Foresi\Identity\Domain\Role;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * With Work installed, the start page of a guest is what is shared with them,
 * the guest home Work gives Identity.
 */
final class GuestStartPageTest extends WebTestCase
{
    private KernelBrowser $client;
    private SignedInBrowser $browser;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'));
    }

    public function testTheStartPageSendsAGuestToWhatIsSharedWithThem(): void
    {
        $this->signIn(Role::Guest);

        $this->client->request('GET', '/');

        self::assertResponseRedirects('/shared');
        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main h1', 'Shared with me');
        self::assertSelectorTextContains('main', 'Nothing is shared with you yet');
        // The sidebar offers what is shared, their inbox, their connected apps and their tasks, nothing else of the
        // modules (the extensions group holds the test environment's own probe item).
        self::assertSame(['/shared', '/notifications', '/connected-apps', '/my-tasks'], $crawler->filter('#sidebar [data-nav-area]:not([data-nav-area="extensions"]) [data-nav-section] li > a')->each(static fn ($link): string => (string) $link->attr('href')));
    }

    private function signIn(Role $role): void
    {
        $this->browser->seed(role: $role);
        $this->browser->signIn();
    }
}
