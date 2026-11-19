<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Infrastructure\Api;

use Foresi\Identity\Application\People;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Identity\Domain\Membership;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Identity\Infrastructure\Security\SecurityUser;
use Foresi\Tests\Shared\Infrastructure\Mcp\ReadToolTestCase;
use Foresi\Work\Application\ManageComments;
use Foresi\Work\Application\ManageLabels;
use Foresi\Work\Application\ManageProjects;
use Foresi\Work\Application\ManageSections;
use Foresi\Work\Application\ManageSharing;
use Foresi\Work\Application\ManageTasks;
use Foresi\Work\Application\ManageTime;
use Foresi\Work\Domain\Project;
use Foresi\Work\Domain\ProjectStatus;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * The work of an organisation over the API: the token's person is a team
 * member, beside an owner who sets up what the tests read. Data is made
 * through the use cases of the screens, signed in as whom the test names.
 */
abstract class WorkApiTestCase extends ReadToolTestCase
{
    protected User $owner;
    protected string $writer;
    private ?string $session = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->fixtures->user('owner@example.com');
        $this->fixtures->join($this->owner, Role::Owner);
        $this->writer = $this->issued([ApiScope::from('projects:write'), ApiScope::from('tasks:write'), ApiScope::from('time_entries:write')]);
    }

    /**
     * Runs the work signed in as the user, the owner unless told otherwise.
     *
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    protected function as(\Closure $work, ?User $user = null): mixed
    {
        $tokens = self::getContainer()->get(TokenStorageInterface::class);
        $tokens->setToken(new UsernamePasswordToken(SecurityUser::fromUser($user ?? $this->owner), 'main', ['ROLE_USER']));
        try {
            return $this->flushed($work);
        } finally {
            $tokens->setToken(null);
        }
    }

    protected function project(string $name = 'Website'): Project
    {
        return $this->as(fn (): Project => $this->service(ManageProjects::class)->create($name, null));
    }

    /**
     * A project only the people it is shared with see, besides the owners.
     */
    protected function chosenProject(string $name = 'Secret'): Project
    {
        $project = $this->project($name);
        $this->as(fn () => $this->service(ManageSharing::class)->changeVisibility($project->id(), \Foresi\Work\Domain\ProjectVisibility::Chosen));

        return $project;
    }

    protected function task(Project $project, string $title, ?string $responsibleId = null): string
    {
        return $this->as(fn (): string => $this->service(ManageTasks::class)->create($project->id(), $title, responsibleId: $responsibleId)->id());
    }

    protected function privateTask(Project $project, string $title): string
    {
        $id = $this->task($project, $title);
        $this->as(fn () => $this->service(ManageSharing::class)->makeTaskPrivate($id, true));

        return $id;
    }

    protected function comment(string $taskId, string $body, bool $internal = false): string
    {
        return $this->as(fn (): string => $this->service(ManageComments::class)->add($taskId, $body, $internal)->id());
    }

    protected function label(string $name): string
    {
        return $this->as(fn (): string => $this->service(ManageLabels::class)->create($name, \Foresi\Work\Domain\LabelColour::Green)->id());
    }

    protected function section(Project $project, string $name): string
    {
        return $this->as(fn (): string => $this->service(ManageSections::class)->add($project->id(), $name)->id());
    }

    protected function logged(Project $project, ?string $taskId, int $minutes, ?User $as = null): string
    {
        return $this->as(fn (): string => $this->service(ManageTime::class)->log($project->id(), $taskId, new \DateTimeImmutable('2026-11-18'), $minutes)->id(), $as);
    }

    /**
     * @return list<ProjectStatus> by position
     */
    protected function statuses(Project $project): array
    {
        return $this->em()->getRepository(ProjectStatus::class)->findBy(['project' => $project->id()], ['position' => 'ASC']);
    }

    protected function membershipOf(User $user): Membership
    {
        return self::getContainer()->get(People::class)->membershipOf($user->id()) ?? throw new \LogicException('No membership.');
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    protected function service(string $class): object
    {
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }

    /**
     * @param array<mixed>|null $body
     */
    protected function send(string $method, string $path, ?array $body = null, ?string $secret = null): void
    {
        $this->client->request($method, $path, server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.($secret ?? $this->writer),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /**
     * Calls a tool over the MCP endpoint with the writer's token and answers its result.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<mixed>
     */
    protected function mcpTool(string $name, array $arguments): array
    {
        $answer = $this->mcpRequest('tools/call', ['name' => $name, 'arguments' => [] === $arguments ? new \stdClass() : $arguments]);
        self::assertArrayHasKey('result', $answer, $name.' '.json_encode($answer, \JSON_THROW_ON_ERROR));
        $result = $answer['result'];
        self::assertIsArray($result);

        return $result;
    }

    /**
     * Sends one request over the MCP endpoint with the writer's token, after the initialisation.
     *
     * @param array<string, mixed> $params
     *
     * @return array<mixed>
     */
    protected function mcpRequest(string $method, array $params = []): array
    {
        if (null === $this->session) {
            $this->mcp('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'test', 'version' => '1.0']]);
        }

        return $this->mcp($method, [] === $params ? new \stdClass() : $params);
    }

    /**
     * One member of each item of a list of the answer: column('id', 'data').
     *
     * @return list<mixed>
     */
    protected function column(string $column, string|int ...$keys): array
    {
        $items = $this->at(...$keys);
        self::assertIsArray($items);

        return array_values(array_map(static fn (mixed $item): mixed => \is_array($item) ? $item[$column] ?? null : null, $items));
    }

    /**
     * @return list<string> the ids of the subjects of the events of the answer, each once
     */
    protected function subjects(): array
    {
        $ids = [];
        foreach ($this->column('subject', 'data') as $subject) {
            self::assertIsArray($subject);
            self::assertIsString($subject['id'] ?? null);
            $ids[$subject['id']] = true;
        }

        return array_keys($ids);
    }

    protected static function in(mixed $value, string|int ...$path): mixed
    {
        foreach ($path as $key) {
            self::assertIsArray($value);
            self::assertArrayHasKey($key, $value);
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * @param array<string, mixed>|\stdClass $params
     *
     * @return array<mixed>
     */
    private function mcp(string $method, array|\stdClass $params): array
    {
        $server = [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->writer,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_MCP_PROTOCOL_VERSION' => '2025-06-18',
        ];
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
}
