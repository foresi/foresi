<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Infrastructure\Api;

use Doctrine\DBAL\Connection;

/**
 * Recurring tasks over REST and MCP (plan 37.4): set up, read, previewed, changed,
 * paused, resumed, a date skipped and ended, each write once per idempotency key,
 * with its events.
 */
final class RecurringTaskApiTest extends WorkApiTestCase
{
    public function testARecurringTaskIsSetUpReadAndPreviewedOverRest(): void
    {
        $project = $this->project();
        $monday = new \DateTimeImmutable('monday next week');

        $this->send('POST', '/api/v1/recurring-tasks', [
            'project_id' => $project->id(),
            'title' => 'Slot {week}',
            'first_on' => $monday->format('Y-m-d'),
            'repeat' => 'week',
            'subtasks' => ['Collect requests'],
        ]);
        self::assertResponseStatusCodeSame(201);
        $id = $this->at('data', 'id');
        self::assertIsString($id);
        self::assertSame('active', $this->at('data', 'state'));
        self::assertSame($monday->format('Y-m-d'), $this->at('data', 'next_on'));
        self::assertSame(['Collect requests'], $this->at('data', 'subtasks'));
        self::assertNull($this->at('data', 'due_days_after'));
        self::assertSame($monday->modify('+6 days')->format('Y-m-d'), $this->at('data', 'next_tasks', 0, 'due_on'));
        self::assertSame('Slot '.(int) $monday->format('W'), $this->at('data', 'next_tasks', 0, 'title'));
        self::assertCount(5, $this->column('on', 'data', 'next_tasks'));

        $this->send('GET', '/api/v1/recurring-tasks');
        self::assertResponseIsSuccessful();
        self::assertSame([$id], $this->column('id', 'data'));
        $this->send('GET', '/api/v1/recurring-tasks?state=ended');
        self::assertSame([], $this->column('id', 'data'));
        $this->send('GET', '/api/v1/recurring-tasks?state=gone');
        self::assertResponseStatusCodeSame(400);

        $this->send('GET', '/api/v1/recurring-tasks/'.$id.'/preview?count=2');
        self::assertResponseIsSuccessful();
        self::assertSame([$monday->format('Y-m-d'), $monday->modify('+7 days')->format('Y-m-d')], $this->column('on', 'data'));
        $this->send('GET', '/api/v1/recurring-tasks/'.$id.'/preview?count=25');
        self::assertResponseStatusCodeSame(400);

        $this->send('GET', '/api/v1/recurring-tasks/'.$id);
        self::assertResponseIsSuccessful();
        self::assertSame($project->id(), $this->at('data', 'project', 'id'));
    }

    public function testItIsChangedPausedResumedSkippedAndEnded(): void
    {
        $id = $this->recurring();
        $next = new \DateTimeImmutable('monday next week');

        $this->send('PATCH', '/api/v1/recurring-tasks/'.$id, ['title' => 'Weekly slot', 'due_days_after' => 2, 'create_days_before' => 1]);
        self::assertResponseIsSuccessful();
        self::assertSame('Weekly slot', $this->at('data', 'title'));
        self::assertSame(2, $this->at('data', 'due_days_after'));
        self::assertSame($next->modify('-1 day')->format('Y-m-d'), $this->at('data', 'next_tasks', 0, 'create_on'));

        $this->send('POST', '/api/v1/recurring-tasks/'.$id.'/skip', ['on' => $next->format('Y-m-d')]);
        self::assertResponseIsSuccessful();
        self::assertSame([$next->format('Y-m-d')], $this->at('data', 'skipped_on'));
        self::assertSame($next->modify('+7 days')->format('Y-m-d'), $this->at('data', 'next_on'));
        $this->send('POST', '/api/v1/recurring-tasks/'.$id.'/skip', ['on' => $next->modify('+1 day')->format('Y-m-d')]);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'on']);

        $this->send('POST', '/api/v1/recurring-tasks/'.$id.'/pause');
        self::assertResponseIsSuccessful();
        self::assertSame('paused', $this->at('data', 'state'));
        self::assertSame([], $this->at('data', 'next_tasks'));
        $this->send('POST', '/api/v1/recurring-tasks/'.$id.'/resume');
        self::assertSame('active', $this->at('data', 'state'));

        $this->send('POST', '/api/v1/recurring-tasks/'.$id.'/end');
        self::assertResponseIsSuccessful();
        self::assertSame('ended', $this->at('data', 'state'));
        $this->send('POST', '/api/v1/recurring-tasks/'.$id.'/pause');
        $this->assertProblem(409, 'recurring_ended');

        self::assertSame([
            'work.recurring_task.created',
            'work.recurring_task.changed',
            'work.recurring_task.occurrence_skipped',
            'work.recurring_task.paused',
            'work.recurring_task.resumed',
            'work.recurring_task.ended',
        ], $this->events());
    }

    public function testAWriteIsDoneOncePerIdempotencyKey(): void
    {
        $project = $this->project();
        $body = json_encode(['project_id' => $project->id(), 'title' => 'Slot', 'first_on' => new \DateTimeImmutable('monday next week')->format('Y-m-d')], \JSON_THROW_ON_ERROR);
        foreach ([1, 2] as $round) {
            $this->client->request('POST', '/api/v1/recurring-tasks', server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->writer,
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_IDEMPOTENCY_KEY' => 'recurring-slot-1',
            ], content: $body);
            self::assertResponseStatusCodeSame(201, (string) $round);
        }
        $this->send('GET', '/api/v1/recurring-tasks');
        self::assertCount(1, $this->column('id', 'data'));
    }

    public function testATaskDueTodayIsCreatedAtOnceAndNamesItsRecurringTask(): void
    {
        $project = $this->project();
        $today = new \DateTimeImmutable('today');
        $this->send('POST', '/api/v1/recurring-tasks', ['project_id' => $project->id(), 'title' => 'Daily check', 'first_on' => $today->format('Y-m-d'), 'repeat' => 'day']);
        $id = $this->at('data', 'id');
        self::assertSame(1, $this->at('data', 'created_count'));
        $taskId = $this->at('data', 'tasks', 0, 'id');
        self::assertIsString($taskId);

        $this->send('GET', '/api/v1/tasks/'.$taskId);
        self::assertSame(['id' => $id, 'name' => 'Daily check'], $this->at('data', 'recurring_task'));
        self::assertSame($today->format('Y-m-d'), $this->at('data', 'occurrence_on'));
    }

    public function testMcpSetsUpReadsAndPreviewsAsRestDoes(): void
    {
        $project = $this->project();
        $monday = new \DateTimeImmutable('monday next week');

        $created = $this->mcpTool('foresi_create_recurring_task', ['project_id' => $project->id(), 'title' => 'Slot', 'first_on' => $monday->format('Y-m-d'), 'idempotency_key' => 'mcp-slot']);
        self::assertFalse($created['isError'] ?? false, json_encode($created, \JSON_THROW_ON_ERROR));
        $id = self::in($created, 'structuredContent', 'data', 'id');
        self::assertIsString($id);

        $listed = $this->mcpTool('foresi_list_recurring_tasks', []);
        self::assertSame([$id], array_column($this->arrayAt($listed, 'structuredContent', 'data', 'data'), 'id'));
        $read = $this->mcpTool('foresi_get_recurring_task', ['recurring_task_id' => $id]);
        self::assertSame('Slot', self::in($read, 'structuredContent', 'data', 'title'));
        $preview = $this->mcpTool('foresi_preview_recurring_task', ['recurring_task_id' => $id, 'count' => 3]);
        self::assertCount(3, $this->arrayAt($preview, 'structuredContent', 'data', 'data'));

        foreach (['foresi_pause_recurring_task', 'foresi_resume_recurring_task'] as $tool) {
            $result = $this->mcpTool($tool, ['recurring_task_id' => $id, 'idempotency_key' => $tool]);
            self::assertFalse($result['isError'] ?? false, $tool);
        }
        $skipped = $this->mcpTool('foresi_skip_recurring_task_occurrence', ['recurring_task_id' => $id, 'on' => $monday->format('Y-m-d'), 'idempotency_key' => 'skip-1']);
        self::assertSame([$monday->format('Y-m-d')], self::in($skipped, 'structuredContent', 'data', 'skipped_on'));
        $ended = $this->mcpTool('foresi_end_recurring_task', ['recurring_task_id' => $id, 'idempotency_key' => 'end-1']);
        self::assertSame('ended', self::in($ended, 'structuredContent', 'data', 'state'));
    }

    private function recurring(): string
    {
        $project = $this->project();
        $this->send('POST', '/api/v1/recurring-tasks', ['project_id' => $project->id(), 'title' => 'Slot', 'first_on' => new \DateTimeImmutable('monday next week')->format('Y-m-d')]);
        self::assertResponseStatusCodeSame(201);
        $id = $this->at('data', 'id');
        self::assertIsString($id);

        return $id;
    }

    /**
     * @param array<mixed> $answer
     *
     * @return array<mixed>
     */
    private function arrayAt(array $answer, string ...$keys): array
    {
        $value = self::in($answer, ...$keys);
        self::assertIsArray($value);

        return $value;
    }

    /**
     * @return list<string>
     */
    private function events(): array
    {
        /** @var list<string> $names */
        $names = self::getContainer()->get(Connection::class)->fetchFirstColumn("SELECT name FROM event_log WHERE name LIKE 'work.recurring_task.%' ORDER BY id");

        return $names;
    }
}
