<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Inbox\Infrastructure;

use Foresi\Inbox\Application\View\InboxAddressLists;
use Foresi\Tests\Ui\Support\ListPageAssertions;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The person's inbox addresses in their account: listed, a new one shown once
 * with no-store, the forwarding help linked to the providers' own pages.
 */
final class AccountInboxControllerTest extends InboxScreenTestCase
{
    use ListPageAssertions;

    public function testTheAddressesAreAListWithViews(): void
    {
        self::assertListWithViews(self::getContainer()->get(InboxAddressLists::class)->list(), 'inbox.addresses', ['all']);
        $rows = static fn (Crawler $crawler): int => $crawler->filter('[data-inbox-addresses] tbody tr[data-id]')->count();

        $crawler = $this->client->request('GET', '/account/inbox');
        self::assertSame(['label', 'address', 'created', 'dropped'], $crawler->filter('[data-inbox-addresses] colgroup col[data-column]')->each(static fn (Crawler $col): string => (string) $col->attr('data-column')));
        $crawler = $this->client->request('GET', '/account/inbox?cols=label,dropped');
        self::assertSame(['label', 'dropped'], $crawler->filter('[data-inbox-addresses] colgroup col[data-column]')->each(static fn (Crawler $col): string => (string) $col->attr('data-column')));
        self::assertListTable($crawler, '[data-inbox-addresses]', ['widths' => '']);

        // A search that finds nothing offers to clear it, not to make a first address.
        $crawler = $this->client->request('GET', '/account/inbox?q=nothing');
        self::assertSame(0, $rows($crawler));
        self::assertCount(0, $crawler->filter('[data-inbox-addresses-empty]'));
        self::assertSame(1, $rows(self::applyBuilder($this->client, '/account/inbox', 'label', 'inbox')));
    }

    public function testTheAccountMenuLinksThePageAndItListsTheAddressMasked(): void
    {
        $crawler = $this->client->request('GET', '/account/inbox');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#user-menu a[href="/account/inbox"]'));
        self::assertStringContainsString('My inbox', $crawler->filter('[data-inbox-addresses]')->text());
        self::assertStringNotContainsString($this->address, (string) $this->client->getResponse()->getContent());
        self::assertCount(0, $crawler->filter('#inbox-address-new'));
        self::assertCount(3, $crawler->filter('[data-inbox-forwarding-provider] a[target="_blank"][rel~="noopener"]'));
        self::assertSame('https://support.google.com/mail/answer/10957', $crawler->filter('[data-inbox-forwarding-provider="gmail"] a')->attr('href'));
    }

    public function testAReplacedAddressIsShownOnceWithNoStore(): void
    {
        $crawler = $this->client->request('GET', '/account/inbox');
        $replace = $crawler->filter('a[href$="/replace"]');
        self::assertCount(1, $replace);

        $crawler = $this->client->request('GET', (string) $replace->attr('href'));
        $crawler = $this->client->submit($crawler->filter('#inbox-address-form')->form());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $new = (string) $crawler->filter('#inbox-address')->attr('value');
        self::assertNotSame('', $new);
        self::assertNotSame($this->address, $new);
        $this->client->request('GET', '/account/inbox');
        self::assertStringNotContainsString($new, (string) $this->client->getResponse()->getContent());
    }

    public function testANewAddressNeedsNoActiveOne(): void
    {
        $crawler = $this->client->request('GET', '/account/inbox');
        $this->client->request('POST', '/account/inbox/'.$this->activeId($crawler).'/revoke', ['_token' => (string) $crawler->filter('form[action$="/revoke"] input[name="_token"]')->attr('value')]);
        self::assertResponseRedirects('/account/inbox');

        $crawler = $this->client->request('GET', '/account/inbox/new');
        $crawler = $this->client->submit($crawler->filter('#inbox-address-form')->form(), ['label' => 'Work mail']);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertNotSame('', (string) $crawler->filter('#inbox-address')->attr('value'));
    }

    private function activeId(Crawler $crawler): string
    {
        preg_match('#/account/inbox/([0-9a-f-]{36})/revoke#', $crawler->html(), $match);

        return $match[1] ?? throw new \LogicException('No revoke link.');
    }
}
