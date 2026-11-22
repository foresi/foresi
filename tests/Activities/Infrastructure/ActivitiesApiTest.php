<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Activities\Infrastructure;

use Foresi\Activities\Application\ActivityTargets;
use Foresi\Events\Infrastructure\Dbal\EventLogTable;
use Foresi\Identity\Application\ApiTokens;
use Foresi\Identity\Application\OAuth\OAuthStore;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Identity\Domain\ApiToken;
use Foresi\Identity\Domain\ApiTokenSecret;
use Foresi\Identity\Domain\OAuth\OAuthClient;
use Foresi\Identity\Domain\OAuth\OAuthClientKind;
use Foresi\Identity\Domain\Role;
use Foresi\Shared\Domain\Id;
use Foresi\Tests\Activities\Support\FakeActivityTarget;
use Foresi\Tests\Shared\Infrastructure\Api\ApiTestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

/**
 * Activities over REST and MCP: the writes declared once with #[Operation]
 * under activities:write, the reads under activities:read, and both for the
 * owners and members only (ADR 0041). The contacts an activity is about are a
 * test double: Party offers the real ones.
 */
final class ActivitiesApiTest extends ApiTestCase
{
    private string $writer;
    private string $contactId;
    private ?string $session = null;

    protected function setUp(): void
    {
        parent::setUp();
        Clock::set(new MockClock('2026-11-22 10:00:00', 'UTC'));
        $contacts = new FakeActivityTarget();
        $this->contactId = Id::generate();
        $contacts->add($this->contactId, 'Example Client a.s.');
        self::getContainer()->set(ActivityTargets::class, new ActivityTargets([$contacts]));
        $this->writer = $this->issued([ApiScope::from('activities:write')]);
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testEveryWriteOverRestAndTheReadsAnswerWhatTheWritesDid(): void
    {
        $this->send('POST', '/api/v1/activity-types', ['name' => 'Site visit', 'key' => 'site_visit', 'timing' => 'timed', 'default_duration_minutes' => 90]);
        self::assertResponseStatusCodeSame(201);
        $visitId = $this->string('data', 'id');
        $this->send('PATCH', '/api/v1/activity-types/'.$visitId, ['name' => 'Visit on site', 'default_duration_minutes' => 120]);
        self::assertSame(['Visit on site', 'site_visit', 'timed', 120, false], [$this->at('data', 'name'), $this->at('data', 'key'), $this->at('data', 'timing'), $this->at('data', 'default_duration_minutes'), $this->at('data', 'archived')]);
        $this->send('POST', '/api/v1/activity-types', ['name' => 'Follow-up', 'key' => 'follow_up', 'timing' => 'date']);
        $followUpId = $this->string('data', 'id');

        $this->send('POST', '/api/v1/activities', ['type_id' => $visitId, 'subject' => 'Roof inspection', 'body' => 'Bring the *drone*.', 'planned_at' => '2026-11-25T10:00:00+01:00', 'links' => [['kind' => 'contact', 'id' => $this->contactId]]]);
        self::assertResponseStatusCodeSame(201);
        $visit = $this->string('data', 'id');
        self::assertSame(['2026-11-25T09:00:00+00:00', 120, false, $this->user->id()], [$this->at('data', 'planned_at'), $this->at('data', 'duration_minutes'), $this->at('data', 'done'), $this->at('data', 'owner_user_id')]);
        self::assertSame([['kind' => 'contact', 'id' => $this->contactId, 'available' => true, 'label' => 'Example Client a.s.']], $this->at('data', 'links'));
        $this->send('PATCH', '/api/v1/activities/'.$visit, ['subject' => 'Roof inspection with the owner', 'duration_minutes' => 60]);
        self::assertSame(['Roof inspection with the owner', 60, 'Bring the *drone*.'], [$this->at('data', 'subject'), $this->at('data', 'duration_minutes'), $this->at('data', 'body')]);

        $this->send('POST', '/api/v1/activities/'.$visit.'/complete', ['outcome' => 'Two roofs need work', 'next_type_id' => $followUpId, 'next_subject' => 'Send the offer', 'next_planned_on' => '2026-11-27']);
        self::assertResponseIsSuccessful();
        self::assertSame([true, 'Two roofs need work', 'Send the offer', '2026-11-27'], [$this->at('data', 'done'), $this->at('data', 'outcome'), $this->at('data', 'next', 'subject'), $this->at('data', 'next', 'planned_on')]);
        $next = $this->string('data', 'next', 'id');

        $this->get('/api/v1/activities', ['linked_kind' => 'contact', 'linked_id' => $this->contactId]);
        self::assertResponseIsSuccessful();
        self::assertSame([$next, $visit], $this->ids());
        $this->get('/api/v1/activities/planned', ['from' => '2026-11-22', 'to' => '2026-12-01']);
        self::assertSame([$next], $this->ids());
        $this->get('/api/v1/activities/planned', ['from' => '2026-11-22', 'to' => '2026-11-22']);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'to', 'reason' => 'window_invalid']);

        $this->send('POST', '/api/v1/activities/'.$next.'/trash');
        self::assertTrue($this->at('data', 'trashed'));
        $this->get('/api/v1/activities', ['linked_kind' => 'contact', 'linked_id' => $this->contactId]);
        self::assertSame([$visit], $this->ids());
        $this->get('/api/v1/activities/'.$next);
        self::assertTrue($this->at('data', 'trashed'), 'One in the trash is still read by its id.');
        $this->send('POST', '/api/v1/activities/'.$next.'/restore');
        self::assertFalse($this->at('data', 'trashed'));

        $this->send('POST', '/api/v1/activity-types/'.$followUpId.'/archive');
        self::assertTrue($this->at('data', 'archived'));
        $this->get('/api/v1/activity-types');
        self::assertNotContains($followUpId, $this->ids());
        $this->get('/api/v1/activity-types', ['include_archived' => '1']);
        self::assertContains($followUpId, $this->ids());

        self::assertSame(['activities.activity.created' => 2, 'activities.activity.updated' => 1, 'activities.activity.done' => 1, 'activities.activity.trashed' => 1, 'activities.activity.restored' => 1], array_map(fn (string $name): int => \count($this->events($name)), array_combine($names = ['activities.activity.created', 'activities.activity.updated', 'activities.activity.done', 'activities.activity.trashed', 'activities.activity.restored'], $names)));
        self::assertStringContainsString('"links":["contact:'.$this->contactId.'"]', (string) $this->events('activities.activity.done')[0]);
        self::assertStringNotContainsString('Roof', (string) $this->events('activities.activity.created')[0], 'Events carry no subject.');
    }

    public function testEveryWriteOverMcpAndTheReadToolsAnswerAsRest(): void
    {
        $call = $this->id($this->call('foresi_create_activity_type', ['name' => 'Video call', 'key' => 'video_call', 'timing' => 'timed', 'default_duration_minutes' => 30]));
        $this->call('foresi_update_activity_type', ['type_id' => $call, 'name' => 'Video meeting']);
        $note = $this->id($this->call('foresi_create_activity_type', ['name' => 'Remark', 'key' => 'remark', 'timing' => 'none']));
        $logged = $this->call('foresi_create_activity', ['type_id' => $note, 'subject' => 'Prefers e-mail', 'links' => [['kind' => 'contact', 'id' => $this->contactId]]]);
        self::assertTrue($this->in($logged, 'structuredContent', 'data', 'done'), 'A note is done when written.');
        $planned = $this->id($this->call('foresi_create_activity', ['type_id' => $call, 'subject' => 'Kick-off', 'planned_at' => '2026-11-24T14:00:00+01:00', 'links' => [['kind' => 'contact', 'id' => $this->contactId]]]));
        $this->call('foresi_update_activity', ['activity_id' => $planned, 'subject' => 'Kick-off call']);
        $this->call('foresi_complete_activity', ['activity_id' => $planned, 'outcome' => 'Good fit']);
        $trashed = $this->id($this->call('foresi_create_activity', ['type_id' => $note, 'subject' => 'Wrong client']));
        $this->call('foresi_trash_activity', ['activity_id' => $trashed]);
        $this->call('foresi_restore_activity', ['activity_id' => $trashed]);
        $this->call('foresi_archive_activity_type', ['type_id' => $note]);

        $this->get('/api/v1/activities/'.$planned);
        $rest = $this->json()['data'];
        self::assertEquals($rest, $this->in($this->call('foresi_get_activity', ['activity_id' => $planned]), 'structuredContent', 'data'));
        $this->get('/api/v1/activities', ['linked_kind' => 'contact', 'linked_id' => $this->contactId]);
        self::assertEquals($this->json()['data'], $this->in($this->call('foresi_list_activities', ['linked_kind' => 'contact', 'linked_id' => $this->contactId]), 'structuredContent', 'data', 'items'));
        self::assertSame([], $this->in($this->call('foresi_list_planned_activities', []), 'structuredContent', 'data', 'items'));
        $this->get('/api/v1/activity-types', ['include_archived' => '1']);
        self::assertEquals($this->json()['data'], $this->in($this->call('foresi_list_activity_types', ['include_archived' => true]), 'structuredContent', 'data', 'items'));
        self::assertSame('Video meeting', $rest['type']['name'] ?? null);
    }

    public function testWithoutActivitiesWriteTheWritesAreRefused(): void
    {
        $reader = $this->issued([ApiScope::from('activities:read')]);
        $this->send('POST', '/api/v1/activity-types', ['name' => 'Visit', 'key' => 'visit', 'timing' => 'timed'], $reader);
        $this->assertProblem(403, 'insufficient_scope');
        $write = $this->tool($reader, 'foresi_create_activity_type', ['name' => 'Visit', 'key' => 'visit', 'timing' => 'timed']);
        self::assertTrue($write['isError'] ?? false);
        self::assertSame('insufficient_scope', $this->in($write, 'structuredContent', 'error', 'code'));

        $this->get('/api/v1/activity-types', secret: $reader);
        self::assertResponseIsSuccessful();
        $this->get('/api/v1/activity-types', secret: $this->issued([ApiScope::from('documents:read')]));
        $this->assertProblem(403, 'insufficient_scope');
    }

    public function testAGuestIsRefusedOnBothSurfaces(): void
    {
        $guest = $this->fixtures->user('guest@example.com');
        $this->fixtures->join($guest, Role::Guest);
        // An agent of a guest that asked for everything holds only what a guest may hold: nothing of Activities.
        $secret = $this->flushed(function () use ($guest): string {
            $now = self::getContainer()->get(ClockInterface::class)->now();
            $client = new OAuthClient('foresi_client_'.bin2hex(random_bytes(8)), OAuthClientKind::Registered, 'Example Agent', ['https://agent.example.com/callback'], $now);
            self::getContainer()->get(OAuthStore::class)->addClient($client);
            $secret = ApiTokenSecret::generateOAuth();
            self::getContainer()->get(ApiTokens::class)->add(new ApiToken($guest->id(), 'Example Agent', [ApiScope::write()], ApiTokenSecret::hash($secret), ApiTokenSecret::lastFour($secret), $now, $now->modify('+1 hour'), $client->id()));

            return $secret;
        });

        // Over REST a guest's token is never accepted (ADR 0041).
        $this->get('/api/v1/activity-types', secret: $secret);
        self::assertResponseStatusCodeSame(401);
        $this->send('POST', '/api/v1/activity-types', ['name' => 'Visit', 'key' => 'visit', 'timing' => 'timed'], $secret);
        self::assertResponseStatusCodeSame(401);

        // Over MCP the connection works, but no tool of Activities is listed or answers.
        $this->session = null;
        $this->mcp($secret, 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'test', 'version' => '1.0']]);
        $tools = array_column((array) $this->in($this->mcp($secret, 'tools/list'), 'result', 'tools'), 'name');
        self::assertSame([], array_values(array_filter($tools, static fn (mixed $name): bool => \is_string($name) && str_contains($name, 'activit'))));
        foreach (['foresi_list_activity_types' => [], 'foresi_create_activity_type' => ['name' => 'Visit', 'key' => 'visit', 'timing' => 'timed']] as $name => $arguments) {
            $call = $this->tool($secret, $name, $arguments);
            self::assertTrue($call['isError'] ?? false, $name);
        }
    }

    public function testCompleteRetriedWithTheSameKeyActsOnce(): void
    {
        $type = $this->typeId();
        $this->send('POST', '/api/v1/activities', ['type_id' => $type, 'subject' => 'Kick-off', 'planned_at' => '2026-11-24T14:00:00+01:00']);
        $activity = $this->string('data', 'id');

        $key = Id::generate();
        $this->send('POST', '/api/v1/activities/'.$activity.'/complete', ['outcome' => 'Good fit'], idempotencyKey: $key);
        self::assertResponseIsSuccessful();
        $first = $this->json();
        $this->send('POST', '/api/v1/activities/'.$activity.'/complete', ['outcome' => 'Good fit'], idempotencyKey: $key);
        self::assertResponseIsSuccessful();
        self::assertSame($first, $this->json());
        self::assertCount(1, $this->events('activities.activity.done'));
        $this->send('POST', '/api/v1/activities/'.$activity.'/complete', ['outcome' => 'Good fit'], idempotencyKey: Id::generate());
        $this->assertProblem(409, 'already_done');

        $this->send('POST', '/api/v1/activities', ['type_id' => $type, 'subject' => 'Follow-up', 'planned_at' => '2026-11-26T14:00:00+01:00']);
        $arguments = ['activity_id' => $this->string('data', 'id'), 'idempotency_key' => Id::generate()];
        $once = $this->tool($this->writer, 'foresi_complete_activity', $arguments);
        $twice = $this->tool($this->writer, 'foresi_complete_activity', $arguments);
        self::assertFalse($once['isError'] ?? false);
        self::assertEquals($this->in($once, 'structuredContent', 'data'), $this->in($twice, 'structuredContent', 'data'));
        self::assertCount(2, $this->events('activities.activity.done'));
    }

    public function testRefusalsNameTheirFields(): void
    {
        $type = $this->typeId();
        foreach ([
            [['type_id' => Id::generate(), 'subject' => 'Kick-off', 'done' => true], 'type_id'],
            [['type_id' => $type, 'subject' => '', 'done' => true], 'subject'],
            [['type_id' => $type, 'subject' => 'Kick-off', 'planned_at' => 'tomorrow'], 'planned_at'],
            [['type_id' => $type, 'subject' => 'Kick-off', 'planned_on' => '2026-11-24'], 'planned_on'],
            [['type_id' => $type, 'subject' => 'Kick-off', 'done' => true, 'links' => [['kind' => 'deal', 'id' => Id::generate()]]], 'links'],
            [['type_id' => $type, 'subject' => 'Kick-off', 'done' => true, 'owner_user_id' => Id::generate()], 'owner_user_id'],
        ] as [$body, $field]) {
            $this->send('POST', '/api/v1/activities', $body);
            self::assertResponseStatusCodeSame(400, $field);
            self::assertSame($field, $this->at('parameter'), json_encode($this->json(), \JSON_THROW_ON_ERROR));
        }
    }

    private function typeId(): string
    {
        $this->send('POST', '/api/v1/activity-types', ['name' => 'Meeting', 'key' => 'meeting_'.substr(Id::generate(), -6), 'timing' => 'timed', 'default_duration_minutes' => 60]);

        return $this->string('data', 'id');
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function send(string $method, string $path, ?array $body = null, ?string $secret = null, ?string $idempotencyKey = null): void
    {
        $server = ['HTTP_AUTHORIZATION' => 'Bearer '.($secret ?? $this->writer), 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if (null !== $idempotencyKey) {
            $server['HTTP_IDEMPOTENCY_KEY'] = $idempotencyKey;
        }
        $this->client->request($method, $path, server: $server, content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));
    }

    private function string(string|int ...$keys): string
    {
        $value = $this->at(...$keys);
        self::assertIsString($value, json_encode($this->json(), \JSON_THROW_ON_ERROR));

        return $value;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<mixed>
     */
    private function call(string $name, array $arguments): array
    {
        $result = $this->tool($this->writer, $name, $arguments);
        self::assertFalse($result['isError'] ?? false, $name.': '.json_encode($result, \JSON_THROW_ON_ERROR));

        return $result;
    }

    /**
     * @param array<mixed> $result
     */
    private function id(array $result): string
    {
        $id = $this->in($result, 'structuredContent', 'data', 'id');
        self::assertIsString($id);

        return $id;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<mixed>
     */
    private function tool(string $secret, string $name, array $arguments): array
    {
        $this->session = null;
        $this->mcp($secret, 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'test', 'version' => '1.0']]);
        $result = $this->in($this->mcp($secret, 'tools/call', ['name' => $name, 'arguments' => [] === $arguments ? new \stdClass() : $arguments]), 'result');
        self::assertIsArray($result);

        return $result;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<mixed>
     */
    private function mcp(string $secret, string $method, array $params = []): array
    {
        $server = ['HTTP_AUTHORIZATION' => 'Bearer '.$secret, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream', 'HTTP_MCP_PROTOCOL_VERSION' => '2025-06-18'];
        if (null !== $this->session) {
            $server['HTTP_MCP_SESSION_ID'] = $this->session;
        }
        $this->client->request('POST', '/mcp', server: $server, content: json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => [] === $params ? new \stdClass() : $params], \JSON_THROW_ON_ERROR));
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

    /**
     * @return list<mixed> the recorded events of this name
     */
    private function events(string $name): array
    {
        return $this->em()->getConnection()->fetchFirstColumn('SELECT '.EventLogTable::DATA.' FROM '.EventLogTable::LOG.' WHERE '.EventLogTable::NAME.' = ?', [$name]);
    }
}
