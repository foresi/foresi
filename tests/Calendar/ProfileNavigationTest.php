<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Calendar;

use Foresi\Identity\Domain\Role;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The page of the calendar feeds is a page of the person's own account: it shows the profile's
 * sidebar, which links to it, and the rail has no entry for the profile.
 */
final class ProfileNavigationTest extends WebTestCase
{
    public function testTheCalendarFeedsIsInTheProfileSidebar(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $browser = new SignedInBrowser($client, self::getContainer()->get('doctrine.orm.entity_manager'));
        $browser->seed('en', Role::Owner);
        $browser->signIn();

        $crawler = $client->request('GET', '/account/calendar');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-nav-area="profile"][data-shown]'));
        self::assertCount(0, $crawler->filter('a[data-nav-area="profile"]'), 'The rail has no entry for the profile.');
        self::assertContains('/account/calendar', $crawler->filter('[data-nav-area="profile"][data-shown] a')->each(static fn ($link): string => (string) $link->attr('href')));
    }
}
