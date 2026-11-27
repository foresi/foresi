<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Calendar\Infrastructure;

use Foresi\Calendar\Domain\CalendarFeed;
use Foresi\Calendar\Domain\FeedToken;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Identity\Domain\Membership;
use Foresi\Identity\Domain\Role;
use Foresi\Party\Domain\Party;
use Foresi\Tests\Instance\Support\InstanceZone;
use Foresi\Tests\Shared\Infrastructure\Api\ApiTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

/**
 * A person's calendar feeds over REST and MCP: a new feed answers its address
 * once, the list never shows it, and a revoked feed stays listed with its address
 * dead. Each person sees and changes only their own.
 */
final class CalendarFeedsApiTest extends ApiTestCase
{
    private string $writer;
    private ?string $session = null;

    protected function setUp(): void
    {
        parent::setUp();
        Clock::set(new MockClock('2026-11-22 10:00:00', 'UTC'));
        InstanceZone::set(self::getContainer());
        $this->writer = $this->issued([ApiScope::from('calendar:write')]);
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testCreateReturnsTheAddressOnceAndTheListNeverShowsIt(): void
    {
        $this->send('POST', '/api/v1/calendar/feeds', ['name' => 'Phone']);

        self::assertResponseStatusCodeSame(201);
        $id = $this->at('data', 'id');
        self::assertIsString($id);
        $address = $this->at('data', 'address');
        self::assertIsString($address);
        self::assertMatchesRegularExpression('#^http://localhost/calendar/feed/'.FeedToken::PATTERN.'\.ics$#', $address);
        self::assertSame('webcal://localhost/calendar/feed/'.basename($address), $this->at('data', 'webcal'));
        self::assertSame(['Phone', '2026-11-22T11:00:00+01:00', null, null], [$this->at('data', 'name'), $this->at('data', 'created_at'), $this->at('data', 'last_fetched_at'), $this->at('data', 'revoked_at')]);
        $token = basename($address, '.ics');
        $feed = $this->em()->find(CalendarFeed::class, $id);
        self::assertInstanceOf(CalendarFeed::class, $feed);
        self::assertSame(hash('sha256', $token), $feed->tokenHash());

        $this->get('/api/v1/calendar/feeds', secret: $this->writer);
        self::assertResponseIsSuccessful();
        self::assertSame([['id' => $id, 'name' => 'Phone', 'created_at' => '2026-11-22T11:00:00+01:00', 'last_fetched_at' => null, 'revoked_at' => null]], $this->json()['data']);
        self::assertStringNotContainsString($token, (string) $this->client->getResponse()->getContent());
    }

    public function testRenamesAndRevokesAFeed(): void
    {
        $id = $this->created('Phone');

        $this->send('PATCH', '/api/v1/calendar/feeds/'.$id, ['name' => 'Work laptop']);
        self::assertResponseIsSuccessful();
        self::assertSame('Work laptop', $this->at('data', 'name'));
        $renamed = $this->at('data');
        self::assertIsArray($renamed);
        self::assertArrayNotHasKey('address', $renamed);

        $this->send('POST', '/api/v1/calendar/feeds/'.$id.'/revoke');
        self::assertResponseIsSuccessful();
        self::assertSame(['Work laptop', '2026-11-22T11:00:00+01:00'], [$this->at('data', 'name'), $this->at('data', 'revoked_at')]);

        $this->get('/api/v1/calendar/feeds', secret: $this->writer);
        self::assertSame('2026-11-22T11:00:00+01:00', $this->at('data', 0, 'revoked_at'));
    }

    public function testAnEmptyNameIsRefused(): void
    {
        $this->send('POST', '/api/v1/calendar/feeds', ['name' => '  ']);

        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'name', 'reason' => 'name_invalid']);
    }

    public function testAnotherPersonsFeedIsNotFound(): void
    {
        $other = $this->flushed(function (): CalendarFeed {
            $someone = $this->fixtures->user('someone@example.com');
            $this->fixtures->join($someone, Role::Member);
            $feed = new CalendarFeed($someone->id(), 'Theirs', FeedToken::hash(FeedToken::generate()), new \DateTimeImmutable('2026-11-21 10:00 UTC'));
            $this->em()->persist($feed);

            return $feed;
        });

        $this->send('PATCH', '/api/v1/calendar/feeds/'.$other->id(), ['name' => 'Mine now']);
        $this->assertProblem(404, 'not_found');
        $this->send('POST', '/api/v1/calendar/feeds/'.$other->id().'/revoke');
        $this->assertProblem(404, 'not_found');
        $this->get('/api/v1/calendar/feeds', secret: $this->writer);
        self::assertSame([], $this->json()['data']);
    }

    public function testTheSameOverMcp(): void
    {
        $created = $this->tool('foresi_create_calendar_feed', ['name' => 'Phone']);
        self::assertFalse($created['isError'] ?? false);
        $id = $this->in($created, 'structuredContent', 'data', 'id');
        self::assertIsString($id);
        self::assertIsString($this->in($created, 'structuredContent', 'data', 'address'));

        $renamed = $this->tool('foresi_rename_calendar_feed', ['feed_id' => $id, 'name' => 'Tablet']);
        self::assertSame('Tablet', $this->in($renamed, 'structuredContent', 'data', 'name'));

        $listed = $this->tool('foresi_list_calendar_feeds', []);
        self::assertFalse($listed['isError'] ?? false);
        self::assertSame([['id' => $id, 'name' => 'Tablet', 'created_at' => '2026-11-22T11:00:00+01:00', 'last_fetched_at' => null, 'revoked_at' => null]], $this->in($listed, 'structuredContent', 'data', 'feeds'));

        $revoked = $this->tool('foresi_revoke_calendar_feed', ['feed_id' => $id]);
        self::assertSame('2026-11-22T11:00:00+01:00', $this->in($revoked, 'structuredContent', 'data', 'revoked_at'));
    }

    public function testATokenWithoutTheCalendarScopeReadsNothing(): void
    {
        $this->get('/api/v1/calendar/feeds', secret: $this->issued([ApiScope::from('firm:read')]));

        $this->assertProblem(403, 'insufficient_scope');
    }

    public function testAGuestIsRefused(): void
    {
        $membership = $this->em()->getRepository(Membership::class)->findOneBy(['user' => $this->user->id()]);
        self::assertInstanceOf(Membership::class, $membership);
        $membership->changeRole(Role::Guest, $this->em()->find(Party::class, $this->customerId));
        $this->em()->flush();

        $this->send('POST', '/api/v1/calendar/feeds', ['name' => 'Phone']);
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
        self::assertSame([], $this->em()->getRepository(CalendarFeed::class)->findAll());
    }

    private function created(string $name): string
    {
        $this->send('POST', '/api/v1/calendar/feeds', ['name' => $name]);
        self::assertResponseStatusCodeSame(201);
        $id = $this->at('data', 'id');
        self::assertIsString($id);

        return $id;
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function send(string $method, string $path, ?array $body = null): void
    {
        $this->client->request($method, $path, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->writer, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => bin2hex(random_bytes(8))], content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<mixed>
     */
    private function tool(string $name, array $arguments): array
    {
        if (null === $this->session) {
            $this->mcp('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'test', 'version' => '1.0']]);
        }
        $arguments += str_starts_with($name, 'foresi_list_') ? [] : ['idempotency_key' => bin2hex(random_bytes(8))];
        $result = $this->in($this->mcp('tools/call', ['name' => $name, 'arguments' => $arguments]), 'result');
        self::assertIsArray($result);

        return $result;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<mixed>
     */
    private function mcp(string $method, array $params = []): array
    {
        $server = ['HTTP_AUTHORIZATION' => 'Bearer '.$this->writer, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream', 'HTTP_MCP_PROTOCOL_VERSION' => '2025-06-18'];
        if (null !== $this->session) {
            $server['HTTP_MCP_SESSION_ID'] = $this->session;
        }
        $this->client->request('POST', '/mcp', server: $server, content: json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        $this->session ??= $this->client->getResponse()->headers->get('Mcp-Session-Id');
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }

    private function in(mixed $value, string|int ...$path): mixed
    {
        foreach ($path as $key) {
            self::assertIsArray($value);
            self::assertArrayHasKey($key, $value);
            $value = $value[$key];
        }

        return $value;
    }
}
