<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Contacts\Integration\DocumentTemplates;

use Foresi\Contacts\Application\CreateParty;
use Foresi\Contacts\Contract\ContactTabs;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\PartyRole;
use Foresi\Tests\Contacts\Infrastructure\Support\ExampleContactTab;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * The Documents tab of a contact: its body is the documents of the contact,
 * with New document opening the dialog with the contact linked.
 */
final class DocumentsContactTabTest extends WebTestCase
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

    public function testDocumentsTabLoadsTheDocumentsOfTheContact(): void
    {
        $id = $this->contact();

        $crawler = $this->client->request('GET', '/contacts/'.$id);
        $tabs = $crawler->filter('main nav[aria-label="Contact sections"] a')->each(static fn (Crawler $a): array => [trim($a->text()), (string) $a->attr('href')]);
        self::assertContains(['Documents', '/contacts/'.$id.'?tab=documents'], $tabs);

        $crawler = $this->client->request('GET', '/contacts/'.$id.'?tab=documents');
        $frame = $crawler->filter('turbo-frame#'.ContactTabs::FRAME);
        self::assertCount(1, $frame);
        self::assertSame('/documents/for/contact/'.$id, $frame->attr('src'));

        $crawler = $this->client->request('GET', '/documents/for/contact/'.$id, server: ['HTTP_TURBO_FRAME' => ContactTabs::FRAME]);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('turbo-frame#'.ContactTabs::FRAME, 'No documents yet');
        $new = $crawler->filter('turbo-frame#'.ContactTabs::FRAME.' a[href*="link_kind=contact"]');
        self::assertCount(1, $new);
        self::assertStringContainsString('link_id='.$id, (string) $new->attr('href'));
    }

    public function testDocumentsTabFollowsTheTabsOfModulesBeforeIt(): void
    {
        // The deals (weight 100) and a module's tab of weight 101 come before the documents.
        ExampleContactTab::$enabled = true;
        $id = $this->contact();

        $crawler = $this->client->request('GET', '/contacts/'.$id);

        $tabs = array_column($crawler->filter('main nav[aria-label="Contact sections"] a')->each(static fn (Crawler $a): array => [trim($a->text())]), 0);
        self::assertSame(['Overview', 'Contact persons', 'Addresses', 'Bank accounts', 'History', 'Deals', 'Example'], \array_slice($tabs, 0, 7));
        self::assertGreaterThan(6, array_search('Documents', $tabs, true));
    }

    private function contact(): string
    {
        // The fixture is set up by the application, not by the signed-in person.
        $tokens = self::getContainer()->get(TokenStorageInterface::class);
        $signedIn = $tokens->getToken();
        $tokens->setToken(null);
        $party = self::getContainer()->get(CreateParty::class)->create(PartyKind::SoleTrader, 'Example Trader', null, null, '', '', [PartyRole::Customer], new Address('Example Street 1', 'Example City', '100 00', 'CZ'));
        $tokens->setToken($signedIn);

        return $party->id();
    }
}
