<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Party\Infrastructure;

use Foresi\Party\Application\CreateParty;
use Foresi\Party\Contract\ContactTabs;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\PartyRole;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Tests\Party\Infrastructure\Support\ExampleContactTab;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * The tabs of a contact: the five of the address book, then the ones other
 * modules add through ContactTabs::SLOT, whose page loads inside the contact.
 */
final class ContactTabsTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'));
        $browser->seed('en');
        $browser->signIn();
    }

    protected function tearDown(): void
    {
        ExampleContactTab::$enabled = false;
        parent::tearDown();
    }

    public function testCoreTabsComeFirst(): void
    {
        $id = $this->contact()->id();

        $crawler = $this->client->request('GET', '/contacts/'.$id.'?tab=addresses');

        self::assertResponseIsSuccessful();
        self::assertSame([
            ['Overview', '/contacts/'.$id],
            ['Contact persons', '/contacts/'.$id.'?tab=contacts'],
            ['Addresses', '/contacts/'.$id.'?tab=addresses'],
            ['Bank accounts', '/contacts/'.$id.'?tab=bank-accounts'],
            ['History', '/contacts/'.$id.'?tab=history'],
        ], \array_slice($this->tabs($crawler), 0, 5));
        self::assertSame('Addresses', trim($crawler->filter('main nav[aria-label="Contact sections"] [aria-current="page"]')->text()));
        self::assertCount(0, $crawler->filter('turbo-frame#'.ContactTabs::FRAME));
    }

    public function testProviderTabAppearsAfterCoreTabsAndOpensByKey(): void
    {
        ExampleContactTab::$enabled = true;
        $id = $this->contact()->id();

        $crawler = $this->client->request('GET', '/contacts/'.$id);
        $tabs = $this->tabs($crawler);
        // The deals follow the five of the address book; the tabs of the Activities and
        // Documents modules come after them (tests/Party/Integration).
        self::assertSame(['Deals', '/contacts/'.$id.'?tab=deals'], $tabs[5]);
        self::assertSame('Overview', trim($crawler->filter('main nav[aria-label="Contact sections"] [aria-current="page"]')->text()));
        self::assertSelectorTextContains('main', 'Billing address');

        $crawler = $this->client->request('GET', '/contacts/'.$id.'?tab=deals');

        self::assertResponseIsSuccessful();
        self::assertSame('Deals', trim($crawler->filter('main nav[aria-label="Contact sections"] [aria-current="page"]')->text()));
        $frame = $crawler->filter('turbo-frame#'.ContactTabs::FRAME);
        self::assertCount(1, $frame);
        self::assertSame('/example-tab/'.$id, $frame->attr('src'));
        self::assertSame('lazy', $frame->attr('loading'));
        // None of the address book's own tab bodies is shown under the module's tab.
        self::assertSelectorNotExists('[data-party-history]');
        self::assertSelectorTextNotContains('main', 'Billing address');
    }

    public function testUnknownTabKeyFallsBackToOverview(): void
    {
        $id = $this->contact()->id();

        // A module's tab that is not installed is no tab at all.
        $crawler = $this->client->request('GET', '/contacts/'.$id.'?tab=deals');

        self::assertResponseIsSuccessful();
        self::assertSame('Overview', trim($crawler->filter('main nav[aria-label="Contact sections"] [aria-current="page"]')->text()));
        self::assertCount(0, $crawler->filter('turbo-frame#'.ContactTabs::FRAME));
    }

    private function contact(): Party
    {
        // The fixture is set up by the application, not by the signed-in person.
        $tokens = self::getContainer()->get(TokenStorageInterface::class);
        $signedIn = $tokens->getToken();
        $tokens->setToken(null);
        $party = self::getContainer()->get(CreateParty::class)->create(PartyKind::SoleTrader, 'Example Trader', null, null, '', '', [PartyRole::Customer], new Address('Example Street 1', 'Example City', '100 00', 'CZ'));
        $tokens->setToken($signedIn);

        return $party;
    }

    /**
     * @return list<array{string, string}>
     */
    private function tabs(Crawler $crawler): array
    {
        return $crawler->filter('main nav[aria-label="Contact sections"] a')->each(static fn (Crawler $a): array => [trim($a->text()), (string) $a->attr('href')]);
    }
}
