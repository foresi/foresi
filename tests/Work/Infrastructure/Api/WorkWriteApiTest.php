<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Infrastructure\Api;

use Foresi\Work\Domain\Task;

/**
 * The writes of projects, tasks and comments over REST and MCP (ADR
 * 0032): the use cases of the screens, the same refusals on both surfaces, and
 * the answer of the read endpoint as the answer of the write.
 */
final class WorkWriteApiTest extends WorkApiTestCase
{
    public function testCreateAnswers201WithTheTaskAndItsLocation(): void
    {
        $project = $this->project();
        $label = $this->label('Copy');
        $me = $this->membershipOf($this->user)->id();

        $this->send('POST', '/api/v1/tasks', ['project_id' => $project->id(), 'title' => 'Write the copy', 'description' => 'For **all** pages.', 'responsible_id' => $me, 'due_on' => '2026-11-30', 'priority' => 'high', 'label_ids' => [$label]]);

        self::assertResponseStatusCodeSame(201);
        $id = $this->at('data', 'id');
        self::assertIsString($id);
        self::assertResponseHeaderSame('Location', '/api/v1/tasks/'.$id);
        $created = $this->json()['data'];
        self::assertSame('For **all** pages.', $this->at('data', 'description'));
        self::assertSame($me, $this->at('data', 'responsible', 'id'));
        self::assertSame('2026-11-30', $this->at('data', 'due_on'));
        self::assertSame('high', $this->at('data', 'priority'));
        self::assertSame([$label], $this->column('id', 'data', 'labels'));
        self::assertSame('open', $this->at('data', 'status', 'type'));

        $this->get('/api/v1/tasks/'.$id, secret: $this->writer);
        self::assertSame($this->json()['data'], $created);
    }

    public function testCreateOverMcpAnswersWhatRestAnswers(): void
    {
        $project = $this->project();

        $call = $this->mcpTool('foresi_create_task', ['project_id' => $project->id(), 'title' => 'From an agent']);

        self::assertFalse($call['isError'] ?? false);
        $created = self::in($call, 'structuredContent', 'data');
        self::assertIsArray($created);
        self::assertIsString($created['id'] ?? null);
        $this->get('/api/v1/tasks/'.$created['id'], secret: $this->writer);
        self::assertSame($this->json()['data'], $created);
        $text = self::in($call, 'content', 0, 'text');
        self::assertIsString($text);
        self::assertStringContainsString('Created the task From an agent.', $text);
    }

    public function testAnUpdateChangesWhatIsSentKeepsTheRestAndCompletesByStatus(): void
    {
        $project = $this->project();
        $id = $this->task($project, 'Draft', $this->membershipOf($this->owner)->id());
        $this->as(fn () => $this->service(\Foresi\Work\Application\ManageTasks::class)->prioritise($id, \Foresi\Work\Domain\Priority::Low));
        $done = $this->statuses($project)[2];

        $this->send('PATCH', '/api/v1/tasks/'.$id, ['title' => 'Final', 'status_id' => $done->id(), 'responsible_id' => null]);

        self::assertResponseIsSuccessful();
        self::assertSame('Final', $this->at('data', 'title'));
        self::assertTrue($this->at('data', 'done'));
        self::assertIsString($this->at('data', 'completed_at'));
        self::assertNull($this->at('data', 'responsible'));
        self::assertSame('low', $this->at('data', 'priority'));

        $this->send('PATCH', '/api/v1/tasks/'.$id, ['title' => str_repeat('x', Task::MAX_TITLE_LENGTH + 1)]);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'title']);

        $this->send('PATCH', '/api/v1/tasks/'.$id, ['due_on' => '30. 11. 2026']);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'due_on', 'reason' => 'date']);
        $this->send('PATCH', '/api/v1/tasks/'.$id, ['due_on' => '2026-02-30']);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'due_on', 'reason' => 'date']);
        $this->send('PATCH', '/api/v1/tasks/'.$id, ['title' => '   ']);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'title', 'reason' => 'name']);

        // A due date sent is kept by a change that leaves it out, and cleared by null.
        $this->send('PATCH', '/api/v1/tasks/'.$id, ['due_on' => '2026-12-01']);
        self::assertSame('2026-12-01', $this->at('data', 'due_on'));
        $this->send('PATCH', '/api/v1/tasks/'.$id, ['priority' => 'urgent']);
        self::assertSame('2026-12-01', $this->at('data', 'due_on'));
        $this->send('PATCH', '/api/v1/tasks/'.$id, ['due_on' => null]);
        self::assertNull($this->at('data', 'due_on'));

        $this->send('PATCH', '/api/v1/tasks/'.\Foresi\Shared\Domain\Id::generate(), []);
        $this->assertProblem(404, 'not_found');
    }

    public function testAnUpdateThatIsRefusedChangesNothing(): void
    {
        $project = $this->project();
        $id = $this->task($project, 'Draft');

        // The title is valid, the status of another project is not: neither is saved.
        $other = $this->statuses($this->project('Other'))[1];
        $this->send('PATCH', '/api/v1/tasks/'.$id, ['title' => 'Changed', 'status_id' => $other->id()]);
        $this->assertProblem(404, 'not_found');

        $this->get('/api/v1/tasks/'.$id, secret: $this->writer);
        self::assertSame('Draft', $this->at('data', 'title'));
    }

    public function testATaskGoesToTheTrashAndComesBack(): void
    {
        $project = $this->project();
        $id = $this->as(fn (): string => $this->service(\Foresi\Work\Application\ManageTasks::class)->create($project->id(), 'Mine')->id(), $this->user);

        $this->send('POST', '/api/v1/tasks/'.$id.'/trash');
        self::assertResponseIsSuccessful();
        self::assertSame(['id' => $id, 'trashed' => true], $this->at('data'));
        $this->get('/api/v1/tasks/'.$id, secret: $this->writer);
        $this->assertProblem(404, 'not_found');

        $this->send('POST', '/api/v1/tasks/'.$id.'/restore');
        self::assertResponseIsSuccessful();
        self::assertSame($id, $this->at('data', 'id'));

        // Only whoever created it or an owner puts it in the trash.
        $theirs = $this->task($this->project('Theirs'), 'Not mine');
        $this->send('POST', '/api/v1/tasks/'.$theirs.'/trash');
        $this->assertProblem(403, 'task_not_yours');
    }

    public function testACommentIsAddedEditedAndDeleted(): void
    {
        $task = $this->task($this->project(), 'Discuss');

        $this->send('POST', '/api/v1/tasks/'.$task.'/comments', ['body' => 'Looks **good**', 'internal' => true]);
        self::assertResponseStatusCodeSame(201);
        $id = $this->at('data', 'id');
        self::assertIsString($id);
        self::assertTrue($this->at('data', 'internal'));
        self::assertSame($this->membershipOf($this->user)->id(), $this->at('data', 'author', 'id'));
        self::assertResponseHeaderSame('Location', '/api/v1/tasks/'.$task.'/comments');

        $this->send('PATCH', '/api/v1/task-comments/'.$id, ['body' => 'Looks great']);
        self::assertResponseIsSuccessful();
        self::assertSame('Looks great', $this->at('data', 'text'));
        self::assertIsString($this->at('data', 'edited_at'));

        $this->send('POST', '/api/v1/tasks/'.$task.'/comments', ['body' => 'Open to all']);
        $open = $this->at('data', 'id');
        self::assertFalse($this->at('data', 'internal'));
        $this->send('PATCH', '/api/v1/task-comments/'.\Foresi\Shared\Domain\Id::generate(), ['body' => 'Lost']);
        $this->assertProblem(404, 'not_found');

        $owners = $this->comment($task, 'From the owner');
        $this->send('PATCH', '/api/v1/task-comments/'.$owners, ['body' => 'Rewritten']);
        $this->assertProblem(403, 'comment_not_yours');

        $this->send('POST', '/api/v1/task-comments/'.$id.'/trash');
        self::assertResponseIsSuccessful();
        self::assertSame(['id' => $id, 'task_id' => $task, 'deleted' => true], $this->at('data'));
        $this->get('/api/v1/tasks/'.$task.'/comments', secret: $this->writer);
        self::assertSame([$open, $owners], $this->ids());

        $this->send('POST', '/api/v1/tasks/'.$task.'/comments', ['body' => '']);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'body', 'reason' => 'comment_empty']);
    }

    public function testAProjectIsCreatedUpdatedArchivedAndRestored(): void
    {
        $this->send('POST', '/api/v1/projects', ['name' => 'Launch', 'workflow' => 'development', 'client_id' => $this->customerId]);
        self::assertResponseStatusCodeSame(201);
        $id = $this->at('data', 'id');
        self::assertIsString($id);
        self::assertResponseHeaderSame('Location', '/api/v1/projects/'.$id);
        self::assertSame('development', $this->at('data', 'workflow'));
        self::assertSame($this->customerId, $this->at('data', 'client', 'id'));

        $this->send('PATCH', '/api/v1/projects/'.$id, ['description' => 'The **launch**.', 'client_id' => null]);
        self::assertResponseIsSuccessful();
        self::assertSame('Launch', $this->at('data', 'name'));
        self::assertSame('The **launch**.', $this->at('data', 'description'));
        self::assertNull($this->at('data', 'client'));

        $this->send('POST', '/api/v1/projects/'.$id.'/archive');
        self::assertTrue($this->at('data', 'archived'));
        $this->send('POST', '/api/v1/tasks', ['project_id' => $id, 'title' => 'Too late']);
        $this->assertProblem(409, 'archived');
        $this->send('POST', '/api/v1/projects/'.$id.'/restore');
        self::assertFalse($this->at('data', 'archived'));

        $this->send('PATCH', '/api/v1/projects/'.\Foresi\Shared\Domain\Id::generate(), ['name' => 'Nowhere']);
        $this->assertProblem(404, 'not_found');

        $this->send('POST', '/api/v1/projects', ['name' => '']);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'name', 'reason' => 'name']);
    }

    public function testAReadTokenCannotWriteAndScopesAreKeptApart(): void
    {
        $project = $this->project();
        $this->send('POST', '/api/v1/tasks', ['project_id' => $project->id(), 'title' => 'Nope'], $this->secret);
        $this->assertProblem(403, 'insufficient_scope');

        $tasksOnly = $this->issued([\Foresi\Identity\Domain\ApiScope::from('tasks:write')]);
        $this->send('POST', '/api/v1/projects', ['name' => 'Nope'], $tasksOnly);
        $this->assertProblem(403, 'insufficient_scope');
    }

    public function testARefusalIsAnsweredAlikeOverMcp(): void
    {
        $call = $this->mcpTool('foresi_create_task', ['project_id' => \Foresi\Shared\Domain\Id::generate(), 'title' => 'Nowhere']);

        self::assertTrue($call['isError'] ?? false);
        self::assertSame('not_found', self::in($call, 'structuredContent', 'error', 'code'));
    }
}
