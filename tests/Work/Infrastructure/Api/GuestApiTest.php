<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Infrastructure\Api;

use Foresi\Identity\Application\ApiTokens;
use Foresi\Identity\Application\ManagePeople;
use Foresi\Identity\Application\OAuth\OAuthStore;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Identity\Domain\ApiToken;
use Foresi\Identity\Domain\ApiTokenSecret;
use Foresi\Identity\Domain\OAuth\OAuthClient;
use Foresi\Identity\Domain\OAuth\OAuthClientKind;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Shared\Infrastructure\Mcp\ReadToolLoader;
use Foresi\Work\Application\ManageSharing;
use Foresi\Work\Domain\Project;
use Psr\Clock\ClockInterface;

/**
 * A guest's own agent over OAuth (ADR 0041): the connection reaches the work
 * shared with the guest and nothing else, every other tool is hidden from it
 * or refused, the access ends with the share or the guest, and a personal
 * token of a guest is never accepted.
 */
final class GuestApiTest extends WorkApiTestCase
{
    /**
     * What a guest's agent sees in tools/list of Work's and the core's: tasks and projects, their custom
     * fields, and whoami. The tools of the modules built on Work, such as the time budget of a project, are
     * left out here; their own tests say what a guest sees of them.
     */
    private const array OTHER_MODULES = ['foresi_get_project_budget'];

    /** Work's and the core's tools a guest's agent sees. */
    private const array GUEST_TOOLS = [
        'foresi_add_task_comment',
        'foresi_create_task',
        'foresi_get_project',
        'foresi_get_task',
        'foresi_list_fields',
        'foresi_list_labels',
        'foresi_list_project_people',
        'foresi_list_task_comments',
        'foresi_search_projects',
        'foresi_search_tasks',
        'foresi_trash_task_comment',
        'foresi_update_profile',
        'foresi_update_task',
        'foresi_update_task_comment',
        'foresi_whoami',
    ];

    private User $guest;
    private Project $project;
    private string $shared;
    private string $hidden;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guest = $this->fixtures->user('guest@example.com');
        $this->fixtures->join($this->guest, Role::Guest);
        $this->project = $this->chosenProject('Client portal');
        $this->shared = $this->task($this->project, 'Approve the logo');
        $this->hidden = $this->task($this->project, 'Internal budget');
        $this->as(fn () => $this->service(ManageSharing::class)->shareTask($this->shared, $this->membershipOf($this->guest)->id()));
        // An agent asking for everything gets what a guest may hold.
        $this->writer = $this->connection($this->guest, [ApiScope::write()]);
    }

    public function testTheConnectionReachesTheTaskSharedWithTheGuest(): void
    {
        $found = $this->mcpTool('foresi_search_tasks', []);
        self::assertFalse($found['isError'] ?? false);
        self::assertSame([$this->shared], array_column($this->list($found, 'structuredContent', 'data', 'items'), 'id'));
        $task = $this->mcpTool('foresi_get_task', ['task_id' => $this->shared]);
        self::assertNull(self::in($task, 'structuredContent', 'data', 'estimate_minutes'), 'The estimate is the time block a guest never sees.');
        self::assertSame('not_found', self::in($this->mcpTool('foresi_get_task', ['task_id' => $this->hidden]), 'structuredContent', 'error', 'code'));

        $comment = $this->mcpTool('foresi_add_task_comment', ['task_id' => $this->shared, 'body' => 'The blue one, please.']);
        self::assertFalse($comment['isError'] ?? false, json_encode($comment, \JSON_THROW_ON_ERROR));
        $internal = $this->mcpTool('foresi_add_task_comment', ['task_id' => $this->shared, 'body' => 'Just between us.', 'internal' => true]);
        self::assertSame('forbidden', self::in($internal, 'structuredContent', 'error', 'code'));
    }

    public function testEveryOtherToolIsHiddenFromTheGuestOrRefused(): void
    {
        $listed = array_column($this->list($this->mcpRequest('tools/list'), 'result', 'tools'), 'name');
        sort($listed);
        self::assertSame(self::GUEST_TOOLS, array_values(array_diff($listed, self::OTHER_MODULES)));

        $all = array_keys($this->service(ReadToolLoader::class)->scopes());
        // Every installed module adds its tools; Work alone already has more than a guest may use.
        self::assertContains('foresi_archive_project', $all);
        self::assertContains('foresi_trash_task', $all);
        $schemas = [];
        foreach (self::getContainer()->get('mcp.server.default.registry')->getTools()->references as $tool) {
            $schemas[$tool->name] = $tool->inputSchema;
        }
        foreach (array_diff($all, $listed) as $tool) {
            $result = $this->mcpTool($tool, self::arguments($schemas[$tool]));
            self::assertTrue($result['isError'] ?? false, $tool);
            self::assertContains(self::in($result, 'structuredContent', 'error', 'code'), ['insufficient_scope', 'forbidden'], $tool);
        }
    }

    public function testAProjectReadsAsTheGuestsProjectScreen(): void
    {
        $this->as(fn () => $this->service(ManageSharing::class)->shareProject($this->project->id(), $this->membershipOf($this->guest)->id()));
        $other = $this->fixtures->user('other-guest@example.com');
        $this->fixtures->join($other, Role::Guest);
        $this->as(fn () => $this->service(ManageSharing::class)->shareProject($this->project->id(), $this->membershipOf($other)->id()));

        $project = $this->mcpTool('foresi_get_project', ['project_id' => $this->project->id()]);
        // Whether its time is billable is the organisation's: a custom field not open to guests.
        self::assertArrayNotHasKey('timesheet_billable_by_default', (array) self::in($project, 'structuredContent', 'data', 'custom_fields'));
        $people = array_column($this->list($this->mcpTool('foresi_list_project_people', ['project_id' => $this->project->id()]), 'structuredContent', 'data', 'items'), 'id');
        self::assertContains($this->membershipOf($this->owner)->id(), $people);
        self::assertNotContains($this->membershipOf($other)->id(), $people, 'A guest is not told who else the organisation works with.');
    }

    public function testTheAccessEndsWhenTheWorkIsUnsharedOrTheGuestRemoved(): void
    {
        $this->as(fn () => $this->service(ManageSharing::class)->unshareTask($this->shared, $this->membershipOf($this->guest)->id()));
        self::assertSame([], $this->list($this->mcpTool('foresi_search_tasks', []), 'structuredContent', 'data', 'items'));
        self::assertSame('not_found', self::in($this->mcpTool('foresi_get_task', ['task_id' => $this->shared]), 'structuredContent', 'error', 'code'));

        $this->as(fn () => $this->service(ManagePeople::class)->deactivate($this->membershipOf($this->guest)->id()));
        $this->assertMcpRefused($this->writer);
    }

    public function testAMemberWhoBecomesAGuestKeepsOnlyTheGuestScopes(): void
    {
        $member = $this->fixtures->user('member@example.com');
        $this->fixtures->join($member, Role::Member);
        $this->writer = $this->connection($member, [ApiScope::from('contacts:read'), ApiScope::from('tasks:write')]);
        // foresi_get_firm is Party's, so it reads with or without the address book.
        self::assertNotSame('insufficient_scope', $this->mcpTool('foresi_get_firm', [])['structuredContent']['error']['code'] ?? null);

        $this->em()->getConnection()->executeStatement("UPDATE membership SET role = 'guest' WHERE id = ?", [$this->membershipOf($member)->id()]);
        self::assertSame('insufficient_scope', self::in($this->mcpTool('foresi_get_firm', []), 'structuredContent', 'error', 'code'));
        self::assertSame(['tasks:write'], self::in($this->mcpTool('foresi_whoami', []), 'structuredContent', 'data', 'token', 'scopes'));
    }

    public function testAPersonalTokenOfAGuestIsRefused(): void
    {
        $personal = $this->connection($this->guest, [ApiScope::from('tasks:write')], personal: true);

        $this->get('/api/v1/me', secret: $personal);
        self::assertResponseStatusCodeSame(401);
        $this->assertMcpRefused($personal);
    }

    private function assertMcpRefused(string $secret): void
    {
        $this->client->request('POST', '/mcp', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$secret,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_MCP_PROTOCOL_VERSION' => '2025-06-18',
        ], content: '{"jsonrpc":"2.0","id":1,"method":"ping"}');
        self::assertResponseStatusCodeSame(401);
    }

    public function testAGuestNeverTrashesATaskThoughTheyMayChangeIt(): void
    {
        $trash = $this->mcpTool('foresi_trash_task', ['task_id' => $this->shared]);
        self::assertSame('forbidden', self::in($trash, 'structuredContent', 'error', 'code'));

        $update = $this->mcpTool('foresi_update_task', ['task_id' => $this->shared, 'title' => 'Approve the new logo']);
        self::assertFalse($update['isError'] ?? false, json_encode($update, \JSON_THROW_ON_ERROR));
    }

    /**
     * An OAuth connection of the person, as the token endpoint makes it; with $personal a token issued by hand.
     *
     * @param list<ApiScope> $scopes
     */
    private function connection(User $user, array $scopes, bool $personal = false): string
    {
        return $this->flushed(function () use ($user, $scopes, $personal): string {
            $now = $this->service(ClockInterface::class)->now();
            $clientId = null;
            if (!$personal) {
                $client = new OAuthClient('foresi_client_'.bin2hex(random_bytes(8)), OAuthClientKind::Registered, 'Example Agent', ['https://agent.example.com/callback'], $now);
                $this->service(OAuthStore::class)->addClient($client);
                $clientId = $client->id();
            }
            $secret = $personal ? ApiTokenSecret::generate() : ApiTokenSecret::generateOAuth();
            $this->service(ApiTokens::class)->add(new ApiToken($user->id(), 'Example Agent', $scopes, ApiTokenSecret::hash($secret), ApiTokenSecret::lastFour($secret), $now, $now->modify('+1 hour'), $clientId));

            return $secret;
        });
    }

    /**
     * Arguments a schema accepts: each required property with a value of its type.
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private static function arguments(array $schema): array
    {
        $properties = (array) ($schema['properties'] ?? []);
        $arguments = [];
        foreach ((array) ($schema['required'] ?? []) as $name) {
            \assert(\is_string($name));
            $arguments[$name] = self::sample((array) ($properties[$name] ?? []));
        }

        return $arguments;
    }

    /**
     * @param array<mixed> $property
     */
    private static function sample(array $property): mixed
    {
        if (isset($property['enum']) && \is_array($property['enum'])) {
            return $property['enum'][0];
        }
        $type = $property['type'] ?? 'string';
        $type = \is_array($type) ? $type[0] : $type;

        $minimum = \is_int($property['minimum'] ?? null) ? $property['minimum'] : 1;
        $minItems = \is_int($property['minItems'] ?? null) ? $property['minItems'] : 0;
        $maxLength = \is_int($property['maxLength'] ?? null) ? $property['maxLength'] : 7;
        $object = self::arguments(array_filter($property, \is_string(...), \ARRAY_FILTER_USE_KEY));

        return match ($type) {
            'integer', 'number' => max(1, $minimum),
            'boolean' => false,
            'array' => $minItems > 0 ? [self::sample((array) ($property['items'] ?? []))] : [],
            // The server decodes an empty JSON object as an empty array, which its schema check takes for a list, so
            // an object with no required member, such as a mapping, is sent with one.
            'object' => [] === $object ? ['kind' => 'Example'] : $object,
            default => match ($property['format'] ?? null) {
                'uuid' => '019a0000-0000-7000-8000-0000000000ff',
                'date' => '2026-11-19',
                'date-time' => '2026-11-19T10:00:00+00:00',
                'uri' => 'https://hooks.example.com/foresi',
                'email' => 'someone@example.com',
                default => isset($property['pattern']) ? '019a0000-0000-7000-8000-0000000000ff' : substr('Example', 0, $maxLength),
            },
        };
    }

    /**
     * @return list<array<mixed>>
     */
    private function list(mixed $value, string ...$path): array
    {
        $items = self::in($value, ...$path);
        self::assertIsArray($items);

        return array_values(array_filter($items, \is_array(...)));
    }
}
