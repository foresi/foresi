<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Integration\Inbox;

use Foresi\Tests\Inbox\Infrastructure\InboxScreenTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The Overview area of the sidebar with both modules installed: the inbox of mail
 * before the notifications of Work.
 */
final class OverviewSidebarTest extends InboxScreenTestCase
{
    public function testTheInboxComesBeforeTheNotifications(): void
    {
        $crawler = $this->client->request('GET', '/notifications');

        self::assertResponseIsSuccessful();
        self::assertSame(['/', '/inbox', '/notifications'], $crawler->filter('#sidebar [data-nav-area="overview"] [data-nav-section] li > a')->each(static fn (Crawler $a): string => (string) $a->attr('href')));
    }
}
