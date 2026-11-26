<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Infrastructure\Api;

use Foresi\Identity\Domain\ApiScope;
use Foresi\Shared\Infrastructure\Api\Cursor;
use Foresi\Work\Application\ManageTasks;
use Foresi\Work\Domain\Priority;
use Foresi\Work\Domain\Project;

/**
 * The reads of projects, tasks, comments, labels and time over REST: what the
 * token's person sees, in the shapes the OpenAPI document gives, newest first
 * and read by cursor.
 */
final class WorkReadApiTest extends WorkApiTestCase
{
    public function testATaskIsWrittenAsTheApiDocumentsIt(): void
    {
        $project = $this->project();
        $me = $this->membershipOf($this->user);
        $id = $this->task($project, 'Write the copy', $me->id());
        $open = $this->statuses($project)[0];

        $this->get('/api/v1/tasks', ['limit' => '1']);

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
        self::assertSame([
            'data' => [[
                'id' => $id,
                'title' => 'Write the copy',
                'project' => ['id' => $project->id(), 'name' => 'Website'],
                'section_id' => null,
                'parent_id' => null,
                'status' => ['id' => $open->id(), 'name' => $open->name(), 'type' => 'open'],
                'responsible' => ['id' => $me->id(), 'name' => $this->user->displayName()],
                'due_on' => null,
                'priority' => 'none',
                'labels' => [],
                'subtask_count' => 0,
                'done' => false,
                'private' => false,
                'starred' => false,
                'url' => 'http://localhost/tasks/'.$id,
                'custom_fields' => [],
            ]],
            'next_cursor' => null,
            'applied_view' => null,
        ], $this->json());
    }

    public function testTheDetailOfATaskHoldsWhatTheListLeavesOut(): void
    {
        $project = $this->project();
        $id = $this->task($project, 'Launch');
        $subtask = $this->as(fn (): string => $this->service(ManageTasks::class)->create($project->id(), 'Check links', parentId: $id)->id());
        $this->as(fn () => $this->service(ManageTasks::class)->describe($id, 'All **pages**.'));

        $this->get('/api/v1/tasks/'.$id);

        self::assertResponseIsSuccessful();
        self::assertSame('All **pages**.', $this->at('data', 'description'));
        self::assertSame(1, $this->at('data', 'subtask_count'));
        self::assertSame([$subtask], $this->column('id', 'data', 'subtasks'));
        self::assertSame([['id' => $this->membershipOf($this->owner)->id(), 'name' => $this->owner->displayName()]], $this->at('data', 'watchers'));
        self::assertNull($this->at('data', 'parent'));
        self::assertNull($this->at('data', 'completed_at'));
        self::assertFalse($this->at('data', 'project_archived'));
        self::assertIsString($this->at('data', 'created_at'));

        $this->get('/api/v1/tasks/'.$subtask);
        self::assertSame(['id' => $id, 'title' => 'Launch'], $this->at('data', 'parent'));
    }

    public function testAssigneeMeAndTheAssignedViewListTheTasksOfThePerson(): void
    {
        $project = $this->project();
        $mine = $this->task($project, 'Mine', $this->membershipOf($this->user)->id());
        $this->task($project, 'Not mine');

        $this->get('/api/v1/tasks', ['assignee' => 'me']);
        self::assertSame([$mine], $this->ids());

        $this->get('/api/v1/tasks', ['view' => 'work.my_tasks.assigned']);
        self::assertSame([$mine], $this->ids());
        self::assertSame('work.my_tasks', $this->at('applied_view', 'list'));

        $this->get('/api/v1/tasks', ['assignee' => 'me', 'finished' => 'true']);
        self::assertSame([], $this->ids());

        $this->get('/api/v1/tasks', ['assignee' => 'somebody']);
        $this->assertProblem(400, 'invalid_parameter');
    }

    public function testTasksAreNarrowedAndSortedAsTheListOfAllTasksDoes(): void
    {
        $project = $this->project();
        $me = $this->membershipOf($this->user)->id();
        $create = fn (string $title, Priority $priority, ?string $responsible = null): string => $this->as(fn (): string => $this->service(ManageTasks::class)->create($project->id(), $title, responsibleId: $responsible, priority: $priority)->id());
        $urgent = $create('Urgent', Priority::Urgent, $me);
        $none = $create('Whenever', Priority::None);
        $high = $create('High', Priority::High);
        $label = $this->label('Design');
        $this->as(fn () => $this->service(ManageTasks::class)->setLabels($none, [$label]));

        $this->get('/api/v1/tasks', ['priority' => 'high,urgent']);
        self::assertSame([$high, $urgent], $this->ids());
        $this->get('/api/v1/tasks', ['sort' => '-priority']);
        self::assertSame([$urgent, $high, $none], $this->ids());
        $this->get('/api/v1/tasks', ['sort' => '-priority', 'limit' => '1', 'cursor' => Cursor::encode($urgent)]);
        self::assertSame([$high], $this->ids());
        $this->get('/api/v1/tasks', ['assignee' => 'none']);
        self::assertSame([$high, $none], $this->ids());
        $this->get('/api/v1/tasks', ['label_id' => $label]);
        self::assertSame([$none], $this->ids());
        $this->get('/api/v1/tasks', ['created_from' => '2099-01-01']);
        self::assertSame([], $this->ids());
        $this->get('/api/v1/tasks', ['view' => 'work.tasks.high', 'sort' => 'title']);
        self::assertSame([$high, $urgent], $this->ids());
        self::assertSame('work.tasks', $this->at('applied_view', 'list'));
        $this->get('/api/v1/tasks', ['filter' => json_encode(['conditions' => [['field' => 'priority', 'op' => 'is', 'value' => 'none']]], \JSON_THROW_ON_ERROR)]);
        self::assertSame([$none], $this->ids());

        $this->get('/api/v1/tasks', ['sort' => 'colour']);
        $this->assertProblem(400, 'invalid_parameter');
    }

    public function testTasksArePagedNewestFirstAndNarrowedByProjectAndText(): void
    {
        $website = $this->project();
        $shop = $this->project('Shop');
        $first = $this->task($website, 'First');
        $second = $this->task($website, 'Second');
        $third = $this->task($shop, 'Third');

        self::assertSame([[$third, $second], [$first]], $this->pages('/api/v1/tasks', 2));

        $this->get('/api/v1/tasks', ['project_id' => $website->id()]);
        self::assertSame([$second, $first], $this->ids());

        $this->get('/api/v1/tasks', ['q' => 'sec']);
        self::assertSame([$second], $this->ids());

        $this->get('/api/v1/tasks', ['cursor' => Cursor::encode($second)]);
        self::assertSame([$first], $this->ids());
    }

    public function testAProjectIsListedAndReadWithItsWorkflowAndSections(): void
    {
        $project = $this->project();
        $section = $this->section($project, 'Design');
        $this->task($project, 'Open task');
        $statuses = $this->statuses($project);

        $this->get('/api/v1/projects');
        self::assertResponseIsSuccessful();
        self::assertSame([[
            'id' => $project->id(),
            'name' => 'Website',
            'client' => null,
            'visibility' => 'organisation',
            'workflow' => 'simple',
            'open_tasks' => 1,
            'overdue_tasks' => 0,
            'unassigned_tasks' => 1,
            'nearest_due_on' => null,
            'archived' => false,
            'custom_fields' => [],
        ]], $this->at('data'));

        $this->get('/api/v1/projects/'.$project->id());
        self::assertSame('', $this->at('data', 'description'));
        self::assertSame(array_map(static fn (\Foresi\Work\Domain\ProjectStatus $status): string => $status->id(), $statuses), $this->column('id', 'data', 'statuses'));
        self::assertSame(['open', 'active', 'done'], $this->column('type', 'data', 'statuses'));
        self::assertSame([['id' => $section, 'name' => 'Design', 'position' => 0]], $this->at('data', 'sections'));

        $this->get('/api/v1/projects', ['archived' => 'true']);
        self::assertSame([], $this->ids());
    }

    public function testAProjectCountsItsOverdueAndUnassignedTasksAndIsFilteredOnThemAsOnTheScreen(): void
    {
        $late = $this->project('Late');
        $calm = $this->project('Calm');
        $empty = $this->project('Empty');
        $me = $this->membershipOf($this->owner)->id();
        $create = fn (Project $project, string $title, ?string $responsible, string $due): string => $this->as(fn (): string => $this->service(ManageTasks::class)->create($project->id(), $title, responsibleId: $responsible, dueOn: new \DateTimeImmutable($due))->id());
        $create($late, 'Behind', null, '-2 days');
        $create($late, 'Mine', $me, '+1 day');
        $create($calm, 'Later', $me, '+3 days');

        $this->get('/api/v1/projects', ['filter' => json_encode(['conditions' => [['field' => 'overdue_tasks', 'op' => 'gt', 'value' => 0]]])]);
        self::assertResponseIsSuccessful();
        self::assertSame([[$late->id(), 2, 1, 1, new \DateTimeImmutable('-2 days')->format('Y-m-d')]], array_map(static fn (array $project): array => [$project['id'], $project['open_tasks'], $project['overdue_tasks'], $project['unassigned_tasks'], $project['nearest_due_on']], $this->at('data')));

        // A project with no task meets a condition that zero meets, newest first as every page.
        $found = $this->mcpTool('foresi_search_projects', ['filter' => ['conditions' => [['field' => 'unassigned_tasks', 'op' => 'eq', 'value' => 0]]]]);
        self::assertFalse($found['isError'] ?? false);
        self::assertSame([$empty->id(), $calm->id()], array_column(self::in($found, 'structuredContent', 'data', 'items'), 'id'));
        self::assertSame(0, self::in($found, 'structuredContent', 'data', 'items', 1, 'overdue_tasks'));

        $this->get('/api/v1/projects', ['filter' => json_encode(['conditions' => [['field' => 'next_due', 'op' => 'is_empty']]])]);
        self::assertSame([$empty->id()], $this->ids());
    }

    public function testThePeopleOfAProjectAreWhoMayBeResponsible(): void
    {
        $project = $this->project();

        $this->get('/api/v1/projects/'.$project->id().'/people');

        self::assertResponseIsSuccessful();
        $names = $this->column('name', 'data');
        self::assertContains($this->user->displayName(), $names);
        self::assertContains($this->owner->displayName(), $names);
        self::assertContains($this->membershipOf($this->user)->id(), $this->column('id', 'data'));
    }

    public function testCommentsAreOldestFirstWithTheInternalOnesForStaff(): void
    {
        $task = $this->task($this->project(), 'Discuss');
        $first = $this->comment($task, 'First **thought**');
        $internal = $this->comment($task, 'Between us', true);

        $this->get('/api/v1/tasks/'.$task.'/comments');

        self::assertResponseIsSuccessful();
        self::assertSame([$first, $internal], $this->ids());
        self::assertSame(['id' => $this->membershipOf($this->owner)->id(), 'name' => $this->owner->displayName()], $this->at('data', 0, 'author'));
        self::assertSame('First **thought**', $this->at('data', 0, 'text'));
        self::assertSame([false, true], $this->column('internal', 'data'));
        self::assertNull($this->at('data', 0, 'edited_at'));
    }

    public function testLabelsAndTimeAreRead(): void
    {
        $label = $this->label('Urgent');
        $project = $this->project();
        $task = $this->task($project, 'Billable work');
        $mine = $this->logged($project, $task, 90, $this->user);
        $this->logged($project, null, 30);

        $this->get('/api/v1/labels');
        self::assertSame([['id' => $label, 'name' => 'Urgent', 'colour' => 'green']], $this->at('data'));

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

    public function testWhatDoesNotExistIsNotFound(): void
    {
        $this->get('/api/v1/tasks/'.\Foresi\Shared\Domain\Id::generate());
        $this->assertProblem(404, 'not_found');

        $this->get('/api/v1/projects/not-an-id');
        $this->assertProblem(404, 'not_found');
    }

    public function testEachResourceHasItsScope(): void
    {
        $projects = $this->issued([ApiScope::from('projects:read')]);

        $this->get('/api/v1/projects', secret: $projects);
        self::assertResponseIsSuccessful();
        foreach (['/api/v1/tasks', '/api/v1/labels', '/api/v1/time-entries'] as $path) {
            $this->get($path, secret: $projects);
            $this->assertProblem(403, 'insufficient_scope');
        }
    }
}
