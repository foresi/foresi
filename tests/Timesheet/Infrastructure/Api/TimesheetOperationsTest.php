<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Timesheet\Infrastructure\Api;

use Foresi\Shared\Infrastructure\Operation\InputSchema;
use Foresi\Shared\Infrastructure\Operation\Operations;
use Foresi\Timesheet\Domain\ProjectBudgetChange;
use Foresi\Timesheet\Domain\TimeEntry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The operations of time and of the time budget of a project as REST and MCP
 * publish them (ADR 0032): time under time_entries, the budget under the
 * scope of the project it belongs to, and the lengths those of the domain.
 */
final class TimesheetOperationsTest extends KernelTestCase
{
    private const array OPERATIONS = [
        'time_entries.log' => ['POST', '/api/v1/time-entries', 'foresi_log_time', 'time_entries:write', false],
        'time_entries.start_timer' => ['POST', '/api/v1/time-entries/timer', 'foresi_start_timer', 'time_entries:write', false],
        'time_entries.stop_timer' => ['POST', '/api/v1/time-entries/{entryId}/stop', 'foresi_stop_timer', 'time_entries:write', false],
        'work.project_budget.add_change' => ['POST', '/api/v1/projects/{projectId}/budget-changes', 'foresi_add_project_budget_change', 'projects:write', false],
        'work.project_budget.remove_change' => ['DELETE', '/api/v1/projects/{projectId}/budget-changes/{changeId}', 'foresi_remove_project_budget_change', 'projects:write', true],
        'work.project_budget.set_counting' => ['PUT', '/api/v1/projects/{projectId}/budget-counting', 'foresi_set_project_budget_counting', 'projects:write', false],
    ];

    public function testEachOperationIsDeclaredUnderTheScopeOfItsResource(): void
    {
        $operations = $this->operations();
        foreach (self::OPERATIONS as $name => [$method, $path, $tool, $scope, $destructive]) {
            $operation = $operations->get($name);
            self::assertSame([$method, $path, $tool, $scope, $destructive], [$operation->operation->method, $operation->operation->path, $operation->operation->tool, $operation->operation->scope, $operation->operation->destructive], $name);
        }
        self::assertSame(201, $operations->get('time_entries.log')->operation->status);
        self::assertSame(201, $operations->get('work.project_budget.add_change')->operation->status);
    }

    public function testTheLengthsAreThoseOfTheDomain(): void
    {
        self::assertSame(['note' => TimeEntry::MAX_NOTE_LENGTH], $this->lengths('time_entries.log'));
        self::assertSame(ProjectBudgetChange::MAX_NOTE_LENGTH, $this->lengths('work.project_budget.add_change')['note'] ?? null);
    }

    /**
     * @return array<string, int> the maximum lengths of the fields by their wire name
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
