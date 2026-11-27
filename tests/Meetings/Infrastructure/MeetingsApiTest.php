<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Meetings\Infrastructure;

use Foresi\Identity\Domain\ApiScope;
use Foresi\Identity\Domain\Membership;
use Foresi\Meetings\Application\Api\ArchiveSeriesInput;
use Foresi\Meetings\Application\Api\CreateActionItemInput;
use Foresi\Meetings\Application\Api\CreateMeetingInput;
use Foresi\Meetings\Application\Api\CreateSeriesInput;
use Foresi\Meetings\Application\Api\RestoreMeetingInput;
use Foresi\Meetings\Application\Api\SetMinutesInput;
use Foresi\Meetings\Application\Api\TrashMeetingInput;
use Foresi\Meetings\Application\Api\UpdateMeetingInput;
use Foresi\Meetings\Application\Api\UpdateSeriesInput;
use Foresi\Meetings\Infrastructure\Mcp\GetMeetingTool;
use Foresi\Meetings\Infrastructure\Mcp\ListSeriesTool;
use Foresi\Meetings\Infrastructure\Mcp\SearchMeetingsTool;
use Foresi\Shared\Contract\Mcp\StaffOnlyTool;
use Foresi\Shared\Contract\Operation\Operation;
use Foresi\Tests\Shared\Infrastructure\Api\ApiTestCase;

/**
 * Series and meetings over REST and MCP: the writes declared once with
 * #[Operation], the reads under meetings:read, and both surfaces answer alike.
 */
final class MeetingsApiTest extends ApiTestCase
{
    private const string AGENDA = "## Numbers\n\n## Next steps\n";

    private string $writer;
    private string $me;
    private ?string $session = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = $this->issued([ApiScope::from('meetings:write'), ApiScope::from('projects:write')]);
        $membership = $this->em()->getRepository(Membership::class)->findOneBy(['user' => $this->user->id()]);
        self::assertInstanceOf(Membership::class, $membership);
        $this->me = $membership->id();
    }

    public function testASeriesAndAMeetingWithTheirMinutesOverRest(): void
    {
        $this->send('POST', '/api/v1/meetings/series', ['name' => 'C-level weekly', 'visibility' => 'members', 'agenda_template' => self::AGENDA, 'member_ids' => [$this->me], 'default_attendee_ids' => [$this->me]]);
        self::assertResponseStatusCodeSame(201);
        $seriesId = $this->at('data', 'id');
        self::assertIsString($seriesId);
        self::assertSame([[['id' => $this->me, 'name' => $this->user->displayName(), 'default_attendee' => true]], 'members'], [$this->at('data', 'members'), $this->at('data', 'visibility')]);

        $this->send('POST', '/api/v1/meetings', ['series_id' => $seriesId, 'held_on' => '2026-11-30']);
        self::assertResponseStatusCodeSame(201);
        $meetingId = $this->at('data', 'id');
        self::assertIsString($meetingId);
        self::assertSame(['C-level weekly – 2026-11-30', self::AGENDA, 1], [$this->at('data', 'title'), $this->at('data', 'minutes'), $this->at('data', 'version')]);
        self::assertSame([$this->me], array_column($this->listed('data', 'attendees'), 'id'));

        $this->send('PUT', '/api/v1/meetings/'.$meetingId.'/minutes', ['minutes' => 'Hired a designer.', 'version' => 1]);
        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->at('data', 'version'));
        $this->send('PUT', '/api/v1/meetings/'.$meetingId.'/minutes', ['minutes' => 'Hired nobody.', 'version' => 1]);
        $this->assertProblem(409, 'stale_minutes', ['current_version' => 2]);

        $this->get('/api/v1/meetings', ['series_id' => $seriesId], $this->writer);
        self::assertSame([$meetingId], array_column($this->listed('data'), 'id'));

        $this->send('POST', '/api/v1/meetings/'.$meetingId.'/trash');
        self::assertTrue($this->at('data', 'trashed'));
        $this->get('/api/v1/meetings', [], $this->writer);
        self::assertSame([], $this->json()['data']);
        $this->send('POST', '/api/v1/meetings/'.$meetingId.'/restore');
        self::assertFalse($this->at('data', 'trashed'));

        $this->send('POST', '/api/v1/meetings/series/'.$seriesId.'/archive');
        self::assertTrue($this->at('data', 'archived'));
        $this->send('POST', '/api/v1/meetings', ['series_id' => $seriesId, 'held_on' => '2026-12-07']);
        $this->assertProblem(409, 'series_archived');
    }

    public function testTheToolsWriteAndReadAsRestDoes(): void
    {
        $series = $this->tool('foresi_create_meeting_series', ['name' => 'Production weekly', 'visibility' => 'organisation', 'member_ids' => [$this->me], 'idempotency_key' => 'create-production-weekly']);
        self::assertFalse($series['isError'] ?? false);
        $seriesId = $this->in($series, 'structuredContent', 'data', 'id');
        self::assertIsString($seriesId);
        $meeting = $this->tool('foresi_create_meeting', ['series_id' => $seriesId, 'held_on' => '2026-11-30', 'idempotency_key' => 'create-production-meeting']);
        $meetingId = $this->in($meeting, 'structuredContent', 'data', 'id');
        self::assertIsString($meetingId);
        $this->tool('foresi_set_meeting_minutes', ['meeting_id' => $meetingId, 'minutes' => 'Shipped the roof order.', 'version' => 1, 'idempotency_key' => 'minutes-production-meeting']);

        $listed = $this->tool('foresi_list_meeting_series', []);
        $this->get('/api/v1/meetings/series', [], $this->writer);
        self::assertSame($this->json()['data'], $this->in($listed, 'structuredContent', 'data', 'items'));
        $people = $this->in($listed, 'structuredContent', 'data', 'people');
        self::assertIsArray($people);
        self::assertContains(['id' => $this->me, 'name' => $this->user->displayName()], $people);

        $found = $this->tool('foresi_search_meetings', ['query' => 'roof']);
        $this->get('/api/v1/meetings', ['q' => 'roof'], $this->writer);
        self::assertSame($this->json()['data'], $this->in($found, 'structuredContent', 'data', 'items'));
        self::assertSame([$meetingId], array_column($this->listed('data'), 'id'));

        $one = $this->tool('foresi_get_meeting', ['id' => $meetingId]);
        $this->get('/api/v1/meetings/'.$meetingId, secret: $this->writer);
        self::assertSame($this->json()['data'], $this->in($one, 'structuredContent', 'data'));
        self::assertSame(['Shipped the roof order.', 2], [$this->at('data', 'minutes'), $this->at('data', 'version')]);
    }

    public function testAnActionItemOverMcpTakesTheQuoteOrIsAppended(): void
    {
        $project = $this->tool('foresi_create_project', ['name' => 'Website', 'idempotency_key' => 'create-website']);
        $projectId = $this->in($project, 'structuredContent', 'data', 'id');
        self::assertIsString($projectId);
        $meeting = $this->tool('foresi_create_meeting', ['title' => 'Kick-off', 'held_on' => '2026-11-30', 'minutes' => "- Send the offer to Acme\n- Call the bank", 'idempotency_key' => 'create-kick-off']);
        $meetingId = $this->in($meeting, 'structuredContent', 'data', 'id');
        self::assertIsString($meetingId);

        $quoted = $this->tool('foresi_create_meeting_action_item', ['meeting_id' => $meetingId, 'project_id' => $projectId, 'title' => 'Send the offer', 'quote' => 'Send the offer', 'idempotency_key' => 'offer']);
        $taskId = $this->in($quoted, 'structuredContent', 'data', 'action_items', 0, 'task_id');
        self::assertIsString($taskId);
        self::assertSame("- [Send the offer](task:{$taskId}) to Acme\n- Call the bank", $this->in($quoted, 'structuredContent', 'data', 'minutes'));
        self::assertSame([2, 'open', false], [$this->in($quoted, 'structuredContent', 'data', 'version'), $this->in($quoted, 'structuredContent', 'data', 'action_items', 0, 'status_type'), $this->in($quoted, 'structuredContent', 'data', 'action_items', 0, 'trashed')]);
        $again = $this->tool('foresi_create_meeting_action_item', ['meeting_id' => $meetingId, 'project_id' => $projectId, 'title' => 'Send the offer', 'quote' => 'Send the offer', 'idempotency_key' => 'offer']);
        self::assertSame($taskId, $this->in($again, 'structuredContent', 'data', 'action_items', 0, 'task_id'));

        $appended = $this->tool('foresi_create_meeting_action_item', ['meeting_id' => $meetingId, 'project_id' => $projectId, 'title' => 'Book the venue', 'idempotency_key' => 'venue']);
        $venueId = $this->in($appended, 'structuredContent', 'data', 'action_items', 1, 'task_id');
        self::assertIsString($venueId);
        self::assertSame("- [Send the offer](task:{$taskId}) to Acme\n- Call the bank\n- [Book the venue](task:{$venueId})\n", $this->in($appended, 'structuredContent', 'data', 'minutes'));

        $missing = $this->in($this->mcp('tools/call', ['name' => 'foresi_create_meeting_action_item', 'arguments' => ['meeting_id' => $meetingId, 'project_id' => $projectId, 'title' => 'Again', 'quote' => 'Send the offer', 'idempotency_key' => 'again']]), 'result');
        self::assertIsArray($missing);
        self::assertTrue($missing['isError'] ?? false);
        self::assertStringContainsString('quote_not_found', json_encode($missing, \JSON_THROW_ON_ERROR));

        $this->get('/api/v1/meetings/'.$meetingId, secret: $this->writer);
        self::assertSame([$taskId, $venueId], array_column($this->listed('data', 'action_items'), 'task_id'));
    }

    public function testScopesDecideWhatATokenDoesAndAGuestHasNoTool(): void
    {
        $this->get('/api/v1/meetings', [], $this->secret);
        self::assertResponseIsSuccessful();
        $this->send('POST', '/api/v1/meetings/series', ['name' => 'Board'], $this->secret);
        $this->assertProblem(403, 'insufficient_scope');
        $this->get('/api/v1/meetings', [], $this->issued([ApiScope::from('firm:read')]));
        $this->assertProblem(403, 'insufficient_scope');

        foreach ([ListSeriesTool::class, SearchMeetingsTool::class, GetMeetingTool::class] as $tool) {
            $read = self::getContainer()->get($tool);
            self::assertInstanceOf(StaffOnlyTool::class, $read);
            self::assertTrue($read->staffOnly());
        }
        foreach ([CreateActionItemInput::class, CreateSeriesInput::class, UpdateSeriesInput::class, ArchiveSeriesInput::class, CreateMeetingInput::class, UpdateMeetingInput::class, SetMinutesInput::class, TrashMeetingInput::class, RestoreMeetingInput::class] as $input) {
            self::assertTrue(new \ReflectionClass($input)->getAttributes(Operation::class)[0]->newInstance()->staffOnly, $input);
        }
    }

    /**
     * @return list<array<mixed>>
     */
    private function listed(string|int ...$keys): array
    {
        $rows = $this->at(...$keys);
        self::assertIsArray($rows);

        return array_values(array_filter($rows, is_array(...)));
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function send(string $method, string $path, ?array $body = null, ?string $secret = null): void
    {
        $this->client->request($method, $path, server: ['HTTP_AUTHORIZATION' => 'Bearer '.($secret ?? $this->writer), 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => bin2hex(random_bytes(8))], content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<mixed>
     */
    private function tool(string $name, array $arguments): array
    {
        $result = $this->in($this->mcp('tools/call', ['name' => $name, 'arguments' => $arguments]), 'result');
        self::assertIsArray($result);
        self::assertFalse($result['isError'] ?? false, json_encode($result, \JSON_THROW_ON_ERROR));

        return $result;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<mixed>
     */
    private function mcp(string $method, array $params = []): array
    {
        if (null === $this->session && 'initialize' !== $method) {
            $this->mcp('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'test', 'version' => '1.0']]);
        }
        $server = ['HTTP_AUTHORIZATION' => 'Bearer '.$this->writer, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream', 'HTTP_MCP_PROTOCOL_VERSION' => '2025-06-18'];
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
}
