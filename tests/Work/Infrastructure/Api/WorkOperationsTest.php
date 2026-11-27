<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Infrastructure\Api;

use Foresi\Shared\Contract\Operation\Omitted;
use Foresi\Shared\Infrastructure\Operation\InputMapper;
use Foresi\Shared\Infrastructure\Operation\InputSchema;
use Foresi\Shared\Infrastructure\Operation\Operations;
use Foresi\Work\Application\Api\UpdateTaskInput;
use Foresi\Work\Domain\Priority;
use Foresi\Work\Domain\Project;
use Foresi\Work\Domain\Task;
use Foresi\Work\Domain\TaskComment;
use Foresi\Work\Domain\WorkflowTemplate;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The operations of Work as REST and MCP publish them (ADR 0032): each under
 * the scope of its resource, its lengths those of the domain, its choices the
 * cases of the domain, and an update that keeps what it is not sent.
 */
final class WorkOperationsTest extends KernelTestCase
{
    private const array OPERATIONS = [
        'tasks.create' => ['POST', '/api/v1/tasks', 'foresi_create_task', 'tasks:write', false],
        'tasks.update' => ['PATCH', '/api/v1/tasks/{taskId}', 'foresi_update_task', 'tasks:write', false],
        'tasks.trash' => ['POST', '/api/v1/tasks/{taskId}/trash', 'foresi_trash_task', 'tasks:write', true],
        'tasks.restore' => ['POST', '/api/v1/tasks/{taskId}/restore', 'foresi_restore_task', 'tasks:write', false],
        'tasks.comment' => ['POST', '/api/v1/tasks/{taskId}/comments', 'foresi_add_task_comment', 'tasks:write', false],
        'task_comments.update' => ['PATCH', '/api/v1/task-comments/{commentId}', 'foresi_update_task_comment', 'tasks:write', false],
        'task_comments.trash' => ['POST', '/api/v1/task-comments/{commentId}/trash', 'foresi_trash_task_comment', 'tasks:write', true],
        'projects.create' => ['POST', '/api/v1/projects', 'foresi_create_project', 'projects:write', false],
        'projects.update' => ['PATCH', '/api/v1/projects/{projectId}', 'foresi_update_project', 'projects:write', false],
        'projects.archive' => ['POST', '/api/v1/projects/{projectId}/archive', 'foresi_archive_project', 'projects:write', false],
        'projects.restore' => ['POST', '/api/v1/projects/{projectId}/restore', 'foresi_restore_project', 'projects:write', false],
    ];

    public function testEachOperationIsDeclaredUnderTheScopeOfItsResource(): void
    {
        $operations = $this->operations();
        foreach (self::OPERATIONS as $name => [$method, $path, $tool, $scope, $destructive]) {
            $operation = $operations->get($name);
            self::assertSame([$method, $path, $tool, $scope, $destructive], [$operation->operation->method, $operation->operation->path, $operation->operation->tool, $operation->operation->scope, $operation->operation->destructive], $name);
        }
        self::assertSame(201, $operations->get('tasks.create')->operation->status);
        self::assertSame(201, $operations->get('tasks.comment')->operation->status);
        self::assertSame(201, $operations->get('projects.create')->operation->status);
    }

    public function testTheLengthsAndChoicesAreThoseOfTheDomain(): void
    {
        self::assertSame(['title' => Task::MAX_TITLE_LENGTH], $this->lengths('tasks.create'));
        self::assertSame(['title' => Task::MAX_TITLE_LENGTH], $this->lengths('tasks.update'));
        self::assertSame(['body' => TaskComment::MAX_BODY_LENGTH], $this->lengths('tasks.comment'));
        self::assertSame(['name' => Project::MAX_NAME_LENGTH], $this->lengths('projects.create'));
        self::assertSame(array_column(Priority::cases(), 'value'), $this->property('tasks.create', 'priority')['enum']);
        self::assertSame(array_column(WorkflowTemplate::cases(), 'value'), $this->property('projects.create', 'workflow')['enum']);
        self::assertSame(['project_id', 'title'], InputSchema::of($this->operations()->get('tasks.create')->fields)['required']);
    }

    public function testAnUpdateKeepsWhatIsLeftOutAndClearsWhatIsNull(): void
    {
        $input = InputMapper::map($this->operations()->get('tasks.update'), ['task_id' => 't', 'responsible_id' => null]);

        self::assertInstanceOf(UpdateTaskInput::class, $input);
        self::assertNull($input->responsibleId);
        self::assertInstanceOf(Omitted::class, $input->title);
        self::assertInstanceOf(Omitted::class, $input->dueOn);
        self::assertInstanceOf(Omitted::class, $input->labelIds);
    }

    /**
     * @return array<mixed>
     */
    private function property(string $operation, string $name): array
    {
        $properties = InputSchema::of($this->operations()->get($operation)->fields)['properties'];
        self::assertIsArray($properties);
        $property = $properties[$name] ?? null;
        self::assertIsArray($property);

        return $property;
    }

    /**
     * @return array<string, int>
     */
    private function lengths(string $operation): array
    {
        $properties = InputSchema::of($this->operations()->get($operation)->fields)['properties'];
        self::assertIsArray($properties);
        $lengths = [];
        foreach ($properties as $wire => $property) {
            if (\is_string($wire) && \is_array($property) && \is_int($property['maxLength'] ?? null)) {
                $lengths[$wire] = $property['maxLength'];
            }
        }

        return $lengths;
    }

    private function operations(): Operations
    {
        self::bootKernel();

        return self::getContainer()->get(Operations::class);
    }
}
