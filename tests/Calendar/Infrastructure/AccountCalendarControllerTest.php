<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Calendar\Infrastructure;

use Foresi\Calendar\Domain\CalendarFeed;
use Foresi\Calendar\Domain\FeedToken;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

/**
 * The calendar feeds in the person's account: listed with name, created and last
 * fetched, a new one in a dialog that shows its address once, and rename and
 * revoke in each feed's menu. A guest has none.
 */
final class AccountCalendarControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private SignedInBrowser $browser;

    protected function setUp(): void
    {
        Clock::set(new MockClock('2026-11-22 10:00:00', 'UTC'));
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'));
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testTheAccountMenuLinksThePage(): void
    {
        $this->signedIn(Role::Member);

        $crawler = $this->client->request('GET', '/account/calendar');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#user-menu a[href="/account/calendar"]'));
        self::assertCount(1, $crawler->filter('[data-calendar-feeds-empty]'));
        self::assertCount(1, $crawler->filter('a#calendar-feed-new[href="/account/calendar/new"][data-dialog]'));
        self::assertStringContainsString('3 to 24 hours', $crawler->filter('[data-calendar-refresh-note]')->text());
    }

    public function testANewFeedShowsItsAddressOnceAndTheListNever(): void
    {
        $this->signedIn(Role::Member);
        $crawler = $this->client->request('GET', '/account/calendar/new');
        self::assertResponseIsSuccessful();

        $crawler = $this->client->submit($crawler->filter('#calendar-feed-form')->form(), ['name' => 'Phone']);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $address = (string) $crawler->filter('#calendar-feed-address')->attr('value');
        self::assertMatchesRegularExpression('#^http://localhost/calendar/feed/'.FeedToken::PATTERN.'\.ics$#', $address);
        self::assertSame('webcal://localhost/calendar/feed/'.basename($address), $crawler->filter('a[data-calendar-feed-webcal]')->attr('href'));
        $token = basename($address, '.ics');

        $crawler = $this->client->request('GET', '/account/calendar');
        self::assertStringNotContainsString($token, (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('Phone', $crawler->filter('[data-calendar-feeds]')->text());
        self::assertStringContainsString('Never', $crawler->filter('[data-calendar-feeds]')->text());
    }

    public function testAnEmptyNameIsRefusedInTheForm(): void
    {
        $this->signedIn(Role::Member);
        $crawler = $this->client->request('GET', '/account/calendar/new');

        $this->client->submit($crawler->filter('#calendar-feed-form')->form(), ['name' => ' ']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->browser->em()->getRepository(CalendarFeed::class)->findAll());
    }

    public function testRenamesAndRevokesAFeed(): void
    {
        $user = $this->signedIn(Role::Member);
        $feed = $this->feed($user, 'Phone');

        $crawler = $this->client->request('GET', '/account/calendar/'.$feed->id().'/rename');
        $this->client->submit($crawler->filter('#calendar-feed-form')->form(), ['name' => 'Work phone']);
        self::assertResponseRedirects('/account/calendar');

        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Work phone', $crawler->filter('[data-calendar-feeds]')->text());
        $revoke = $crawler->filter('form[action="/account/calendar/'.$feed->id().'/revoke"]');
        self::assertCount(1, $revoke);
        $this->client->submit($revoke->form());
        self::assertResponseRedirects('/account/calendar');

        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Revoked', $crawler->filter('[data-calendar-feeds]')->text());
        self::assertCount(0, $crawler->filter('form[action="/account/calendar/'.$feed->id().'/revoke"]'));
        $this->browser->em()->clear();
        self::assertTrue($this->browser->em()->find(CalendarFeed::class, $feed->id())?->isRevoked());
    }

    public function testARevokeWithoutTheTokenIsRefused(): void
    {
        $user = $this->signedIn(Role::Member);
        $feed = $this->feed($user, 'Phone');

        $this->client->request('POST', '/account/calendar/'.$feed->id().'/revoke', ['_token' => 'wrong']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testSomeoneElsesFeedIsNotFound(): void
    {
        $this->signedIn(Role::Member);
        $other = new User('someone@example.com', 'x', 'Example Company');
        $this->browser->em()->persist($other);
        $feed = $this->feed($other, 'Theirs');

        $this->client->request('GET', '/account/calendar/'.$feed->id().'/rename');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAGuestHasNoCalendarFeeds(): void
    {
        $this->signedIn(Role::Guest);

        foreach (['/account/calendar', '/account/calendar/new'] as $page) {
            $this->client->request('GET', $page);
            self::assertResponseStatusCodeSame(403, $page);
        }
        $crawler = $this->client->request('GET', '/account/notifications');
        self::assertCount(0, $crawler->filter('a[href="/account/calendar"]'));
    }

    private function signedIn(Role $role): User
    {
        $user = $this->browser->seed(role: $role);
        $this->browser->signIn();

        return $user;
    }

    private function feed(User $user, string $name): CalendarFeed
    {
        $feed = new CalendarFeed($user->id(), $name, FeedToken::hash(FeedToken::generate()), new \DateTimeImmutable('2026-11-20 10:00 UTC'));
        $em = $this->browser->em();
        $em->persist($feed);
        $em->flush();

        return $feed;
    }
}
