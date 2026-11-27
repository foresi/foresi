<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Timesheet\Infrastructure\Api;

use Foresi\Identity\Domain\ApiScope;
use Foresi\Shared\Domain\Id;
use Foresi\Tests\Timesheet\Support\LogsTime;
use Foresi\Tests\Work\Infrastructure\Api\WorkApiTestCase;
use Foresi\Timesheet\Infrastructure\Mcp\SearchTimeEntriesTool;

/**
 * Time over REST and MCP (ADR 0032): read with time_entries:read, logged and
 * timed with time_entries:write, which a token for projects and tasks lacks.
 */
final class TimeApiTest extends WorkApiTestCase
{
    use LogsTime;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = $this->issued([ApiScope::from('projects:write'), ApiScope::from('tasks:write'), ApiScope::from('time_entries:write')]);
    }

    public function testTimeIsLoggedAndATimerStartedAndStopped(): void
    {
        $project = $this->project();
        $task = $this->task($project, 'Build');

        $this->send('POST', '/api/v1/time-entries', ['project_id' => $project->id(), 'task_id' => $task, 'worked_on' => '2026-11-18', 'minutes' => 45, 'note' => 'Templates']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(45, $this->at('data', 'minutes'));
        self::assertSame('Templates', $this->at('data', 'note'));

        $this->send('POST', '/api/v1/time-entries', ['project_id' => $project->id(), 'worked_on' => '2026-11-18', 'minutes' => 45, 'started_at' => '2026-11-18T09:00:00+01:00', 'ended_at' => '2026-11-18T10:00:00+01:00']);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'minutes', 'reason' => 'duration']);
        $this->send('POST', '/api/v1/time-entries', ['project_id' => $project->id(), 'worked_on' => '2026-11-18', 'started_at' => '2026-11-18 09:00', 'ended_at' => '2026-11-18T10:00:00+01:00']);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'started_at', 'reason' => 'date']);
        $this->send('POST', '/api/v1/time-entries', ['project_id' => $project->id(), 'worked_on' => '18. 11. 2026', 'minutes' => 5]);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'worked_on', 'reason' => 'date']);

        $this->send('POST', '/api/v1/time-entries/timer', ['project_id' => $project->id(), 'task_id' => $task]);
        self::assertResponseStatusCodeSame(201);
        $timer = $this->at('data', 'id');
        self::assertIsString($timer);
        self::assertTrue($this->at('data', 'running'));
        self::assertNull($this->at('data', 'minutes'));

        $this->send('POST', '/api/v1/time-entries/'.Id::generate().'/stop');
        $this->assertProblem(409, 'timer_not_running');
        $this->send('POST', '/api/v1/time-entries/'.$timer.'/stop');
        self::assertResponseIsSuccessful();
        self::assertFalse($this->at('data', 'running'));
    }

    public function testTimeIsRead(): void
    {
        $project = $this->project();
        $task = $this->task($project, 'Billable work');
        $mine = $this->logged($project, $task, 90, $this->user);
        $this->logged($project, null, 30);

        $this->get('/api/v1/time-entries', ['person' => 'me']);
        self::assertSame([$mine], $this->ids());
        self::assertSame(['id' => $task, 'title' => 'Billable work'], $this->at('data', 0, 'task'));
        self::assertSame(90, $this->at('data', 0, 'minutes'));
        self::assertSame('2026-11-18', $this->at('data', 0, 'worked_on'));
        self::assertFalse($this->at('data', 0, 'running'));

        $this->get('/api/v1/time-entries', ['project_id' => $project->id()]);
        self::assertCount(2, $this->ids());

        $this->get('/api/v1/time-entries', ['from' => '19.11.2026']);
        $this->assertProblem(400, 'invalid_parameter');
    }

    public function testTheSearchToolAnswersWhatRestAnswers(): void
    {
        $project = $this->project();
        $task = $this->task($project, 'Mine');
        $this->logged($project, $task, 30, $this->user);
        $tool = $this->tool(SearchTimeEntriesTool::class);

        self::assertSame(['foresi_search_time_entries', 'time_entries:read'], [$tool->name(), $tool->scope()]);
        self::assertSame(['project_id', 'task_id', 'person', 'from', 'until', 'limit', 'cursor'], self::argumentNames($tool));
        self::assertSame($this->rest('/api/v1/time-entries'), $this->call(SearchTimeEntriesTool::class)->data['items']);
    }

    public function testEachScopeIsKeptApart(): void
    {
        $project = $this->project();
        $tasksOnly = $this->issued([ApiScope::from('tasks:write')]);
        $this->send('POST', '/api/v1/time-entries', ['project_id' => $project->id(), 'worked_on' => '2026-11-18', 'minutes' => 5], $tasksOnly);
        $this->assertProblem(403, 'insufficient_scope');
        $this->send('POST', '/api/v1/time-entries/'.Id::generate().'/stop', [], $tasksOnly);
        $this->assertProblem(403, 'insufficient_scope');

        $this->get('/api/v1/time-entries', secret: $this->issued([ApiScope::from('projects:read')]));
        $this->assertProblem(403, 'insufficient_scope');
    }
}
