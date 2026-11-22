<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Calendar\Infrastructure;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Calendar\Application\CalendarFeedReader;
use Foresi\Calendar\Application\CalendarStore;
use Foresi\Calendar\Application\IcsWriter;
use Foresi\Calendar\Contract\CalendarEntry;
use Foresi\Calendar\Domain\CalendarFeed;
use Foresi\Calendar\Domain\FeedToken;
use Foresi\Identity\Application\StaffCheck;
use Foresi\Identity\Domain\Membership;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;
use Foresi\Tests\Calendar\Support\FakeEntrySource;
use Foresi\Tests\Calendar\Support\RecordingLogger;
use Foresi\Tests\Identity\Support\IdentityFixtures;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

/**
 * The public feed address: the entries of its owner only, from every source that
 * answers, and the same 404 for whatever is not an open feed (review focus 4 of plan 32).
 */
final class CalendarFeedControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private IdentityFixtures $fixtures;
    private MockClock $clock;
    private User $owner;
    private User $colleague;
    private FakeEntrySource $source;
    private RecordingLogger $logger;
    /** @var list<FakeEntrySource>|null the sources until the first request */
    private ?array $sources = null;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-22 10:00:00', 'UTC');
        Clock::set($this->clock);
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->fixtures = new IdentityFixtures($this->em());
        $this->fixtures->rebuildSchema();
        $this->owner = $this->fixtures->user('owner@example.com');
        $this->fixtures->join($this->owner, Role::Owner);
        $this->colleague = $this->fixtures->user('colleague@example.com');
        $this->fixtures->join($this->colleague, Role::Member);
        $this->source = new FakeEntrySource('test.planned', [
            $this->owner->id() => [$this->entry('mine', 'Meeting with Example Customer')],
            $this->colleague->id() => [$this->entry('theirs', 'Colleague dentist')],
        ]);
        $this->logger = new RecordingLogger();
        $this->sources = [$this->source];
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testValidTokenReturnsEntriesOfItsOwnerOnly(): void
    {
        $token = $this->feed($this->owner, 'Phone');

        $this->fetch($token);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/calendar; charset=utf-8');
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString("X-WR-CALNAME:Phone\r\n", $body);
        self::assertStringContainsString("SUMMARY:Meeting with Example Customer\r\n", $body);
        self::assertStringContainsString("UID:test.planned-mine@foresi.example.com\r\n", $body);
        self::assertStringNotContainsString('Colleague', $body);
        // The window of P6: from 30 days ago to 365 days ahead, in the instance's days (UTC without a firm).
        self::assertSame([[$this->owner->id(), '2026-10-23 00:00 UTC', '2027-11-23 00:00 UTC']], $this->source->asked);
    }

    public function testAFeedWithNoEntriesIsAValidEmptyCalendar(): void
    {
        $this->sources([]);
        $token = $this->feed($this->owner, 'Phone');

        $this->fetch($token);

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringStartsWith("BEGIN:VCALENDAR\r\n", $body);
        self::assertStringEndsWith("END:VCALENDAR\r\n", $body);
        self::assertStringNotContainsString('BEGIN:VEVENT', $body);
    }

    public function testRevokedAndUnknownToken404(): void
    {
        $revoked = $this->feed($this->owner, 'Old phone', revoked: true);
        $left = $this->feed($this->colleague, 'Laptop');
        $this->becomeGuest($this->colleague);

        foreach ([$revoked, FeedToken::generate(), 'not-a-token', $left] as $token) {
            $this->fetch($token);
            self::assertResponseStatusCodeSame(404, $token);
            self::assertStringNotContainsString('BEGIN:VCALENDAR', (string) $this->client->getResponse()->getContent());
        }
        self::assertSame([], $this->source->asked);
    }

    public function testRateLimit429(): void
    {
        $token = $this->feed($this->owner, 'Phone');
        for ($fetch = 1; $fetch <= 30; ++$fetch) {
            $this->fetch($token);
            self::assertResponseIsSuccessful((string) $fetch);
        }

        $this->fetch($token);

        self::assertResponseStatusCodeSame(429);
        self::assertTrue($this->client->getResponse()->headers->has('Retry-After'));
        // Another feed has its own budget.
        $this->fetch($this->feed($this->owner, 'Tablet'));
        self::assertResponseIsSuccessful();
    }

    public function testNoSessionCookieSet(): void
    {
        $token = $this->feed($this->owner, 'Phone');

        $this->fetch($token);

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
        self::assertFalse($this->client->getResponse()->headers->has('Set-Cookie'));
        self::assertSame([], $this->client->getCookieJar()->all());
    }

    public function testFailingSourceIsSkipped(): void
    {
        $this->sources([new FakeEntrySource('test.broken', fails: true), $this->source]);
        $token = $this->feed($this->owner, 'Phone');

        $this->fetch($token);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('SUMMARY:Meeting with Example Customer', (string) $this->client->getResponse()->getContent());
        self::assertCount(1, $this->logger->records);
        self::assertSame(['error', 'test.broken'], [$this->logger->records[0][0], $this->logger->records[0][2]['source'] ?? null]);
    }

    public function testAFetchIsRecordedAtMostOnceAnHour(): void
    {
        $token = $this->feed($this->owner, 'Phone');

        $this->fetch($token);
        $this->clock->modify('+30 minutes');
        $this->fetch($token);

        $this->em()->clear();
        $feed = $this->em()->getRepository(CalendarFeed::class)->findOneBy(['tokenHash' => FeedToken::hash($token)]);
        self::assertInstanceOf(CalendarFeed::class, $feed);
        self::assertEquals(new \DateTimeImmutable('2026-11-22 10:00:00 UTC'), $feed->lastFetchedAt());
    }

    /**
     * Replaces the sources, before the first request.
     *
     * @param list<FakeEntrySource> $sources
     */
    private function sources(array $sources): void
    {
        $this->sources = $sources;
    }

    private function fetch(string $token): void
    {
        if (null !== $this->sources) {
            $this->serve($this->sources);
            $this->sources = null;
        }
        $this->client->request('GET', '/calendar/feed/'.$token.'.ics');
    }

    /**
     * @param list<FakeEntrySource> $sources
     */
    private function serve(array $sources): void
    {
        $container = self::getContainer();
        $container->set(CalendarFeedReader::class, new CalendarFeedReader($sources, $container->get(CalendarStore::class), $container->get(StaffCheck::class), $container->get(InstanceClock::class), new IcsWriter(), $this->logger));
    }

    private function feed(User $user, string $name, bool $revoked = false): string
    {
        $token = FeedToken::generate();
        $feed = new CalendarFeed($user->id(), $name, FeedToken::hash($token), new \DateTimeImmutable('2026-11-20 10:00 UTC'));
        if ($revoked) {
            $feed->revoke(new \DateTimeImmutable('2026-11-21 10:00 UTC'));
        }
        $this->em()->persist($feed);
        $this->em()->flush();

        return $token;
    }

    private function becomeGuest(User $user): void
    {
        $contact = new Party(PartyKind::Company, 'Example Guest a.s.', new RegistrationNumber('CZ', '87654320'), VatId::fromString('CZ87654320'), '+420 000 000 000');
        $this->em()->persist($contact);
        $membership = $this->em()->getRepository(Membership::class)->findOneBy(['user' => $user->id()]);
        self::assertInstanceOf(Membership::class, $membership);
        $membership->changeRole(Role::Guest, $contact);
        $this->em()->flush();
    }

    private function entry(string $id, string $title): CalendarEntry
    {
        return new CalendarEntry('test.planned-'.$id.'@foresi.example.com', $title, new \DateTimeImmutable('2026-12-01 09:00 UTC'), new \DateTimeImmutable('2026-12-01 10:00 UTC'), false, new \DateTimeImmutable('2026-11-20 08:00 UTC'), null);
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
