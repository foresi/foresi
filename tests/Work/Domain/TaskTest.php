<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Work\Domain;

use App\Identity\Domain\Membership;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Shared\Domain\Id;
use App\Work\Domain\Priority;
use App\Work\Domain\Project;
use App\Work\Domain\ProjectStatus;
use App\Work\Domain\Section;
use App\Work\Domain\StatusType;
use App\Work\Domain\Task;
use App\Work\Domain\TaskWatcher;
use App\Work\Domain\WorkflowTemplate;
use PHPUnit\Framework\TestCase;

final class TaskTest extends TestCase
{
    private Project $project;

    private ProjectStatus $open;

    protected function setUp(): void
    {
        $this->project = $this->project();
        $this->open = new ProjectStatus($this->project, 'To do', StatusType::Open, 0);
    }

    public function testANewTaskTrimsItsTitleAndStartsWithDefaults(): void
    {
        $task = $this->task('  Write the brief  ');

        self::assertSame('Write the brief', $task->title());
        self::assertSame('', $task->description());
        self::assertSame($this->open, $task->status());
        self::assertSame(Priority::None, $task->priority());
        self::assertNull($task->section());
        self::assertNull($task->responsible());
        self::assertNull($task->dueDate());
        self::assertNull($task->completedAt());
        self::assertFalse($task->isSubtask());
        self::assertTrue(Id::isValid($task->id()));
    }

    public function testATitleCannotBeEmptyOrTooLong(): void
    {
        $task = $this->task();
        try {
            $task->rename('   ');
            self::fail('An empty title is refused.');
        } catch (\DomainException) {
        }
        $task->rename(str_repeat('a', Task::MAX_TITLE_LENGTH));

        $this->expectException(\DomainException::class);
        $task->rename(str_repeat('a', Task::MAX_TITLE_LENGTH + 1));
    }

    public function testAnEstimateIsPositiveAndBounded(): void
    {
        $task = $this->task();
        self::assertNull($task->estimateMinutes());

        $task->estimate(300);
        self::assertSame(300, $task->estimateMinutes());
        $task->estimate(Task::MAX_ESTIMATE_MINUTES);
        self::assertSame(Task::MAX_ESTIMATE_MINUTES, $task->estimateMinutes());
        foreach ([0, -1, Task::MAX_ESTIMATE_MINUTES + 1] as $minutes) {
            try {
                $task->estimate($minutes);
                self::fail(sprintf('An estimate of %d minutes is refused.', $minutes));
            } catch (\DomainException) {
            }
        }
        $task->estimate(null);
        self::assertNull($task->estimateMinutes());
    }

    public function testEnteringAFinishedStatusRecordsTheCompletionAndLeavingItClearsIt(): void
    {
        $task = $this->task();
        $done = new ProjectStatus($this->project, 'Done', StatusType::Done, 1);
        $closed = new ProjectStatus($this->project, 'Closed', StatusType::Closed, 2);
        $finished = new \DateTimeImmutable('2026-11-07 10:00');

        $task->moveToStatus($done, $finished);
        self::assertEquals($finished, $task->completedAt());
        self::assertTrue($task->isCompleted());

        $task->moveToStatus($closed, new \DateTimeImmutable('2026-11-08 10:00'));
        self::assertEquals($finished, $task->completedAt(), 'Moving between finished statuses keeps the date.');

        $task->moveToStatus($this->open, new \DateTimeImmutable('2026-11-09 10:00'));
        self::assertNull($task->completedAt());
    }

    public function testATaskCreatedInAFinishedStatusIsCompletedWhenCreated(): void
    {
        $created = new \DateTimeImmutable('2026-11-07 09:00');
        $task = new Task($this->project, 'Done already', new ProjectStatus($this->project, 'Done', StatusType::Done, 1), 0, Id::generate(), $created);

        self::assertEquals($created, $task->completedAt());
    }

    public function testTheStatusMustBeOfTheSameProject(): void
    {
        $task = $this->task();

        $this->expectException(\DomainException::class);
        $task->moveToStatus(new ProjectStatus($this->project(), 'Elsewhere', StatusType::Open, 0), new \DateTimeImmutable());
    }

    public function testANewTaskRefusesAStatusOfAnotherProject(): void
    {
        $this->expectException(\DomainException::class);
        new Task($this->project, 'Task', new ProjectStatus($this->project(), 'Elsewhere', StatusType::Open, 0), 0, Id::generate(), new \DateTimeImmutable());
    }

    public function testTheSectionMustBeOfTheSameProject(): void
    {
        $task = $this->task();
        $section = new Section($this->project, 'Design', 0);

        $task->moveToSection($section);
        self::assertSame($section, $task->section());
        $task->moveToSection(null);
        self::assertNull($task->section());

        $this->expectException(\DomainException::class);
        $task->moveToSection(new Section($this->project(), 'Elsewhere', 0));
    }

    public function testASubtaskSitsOneLevelUnderATaskOfTheSameProject(): void
    {
        $parent = $this->task('Parent');
        $subtask = new Task($this->project, 'Subtask', $this->open, 0, Id::generate(), new \DateTimeImmutable(), parent: $parent);

        self::assertTrue($subtask->isSubtask());
        self::assertSame($parent, $subtask->parent());
        self::assertFalse($parent->isSubtask());

        try {
            new Task($this->project, 'Too deep', $this->open, 0, Id::generate(), new \DateTimeImmutable(), parent: $subtask);
            self::fail('A subtask of a subtask is refused.');
        } catch (\DomainException) {
        }

        $other = $this->project();
        $this->expectException(\DomainException::class);
        new Task($other, 'Elsewhere', new ProjectStatus($other, 'To do', StatusType::Open, 0), 0, Id::generate(), new \DateTimeImmutable(), parent: $parent);
    }

    public function testASubtaskInheritsNothingElseFromItsParent(): void
    {
        $parent = $this->task('Parent');
        $parent->prioritise(Priority::Urgent);
        $parent->dueOn(new \DateTimeImmutable('2026-12-01'));
        $parent->moveToSection(new Section($this->project, 'Design', 0));

        $subtask = new Task($this->project, 'Subtask', $this->open, 0, Id::generate(), new \DateTimeImmutable(), parent: $parent);

        self::assertSame(Priority::None, $subtask->priority());
        self::assertNull($subtask->dueDate());
        self::assertNull($subtask->section());
    }

    public function testChanges(): void
    {
        $task = $this->task();
        $membership = new Membership(new User('testing@example.com', 'hash', 'Test Person'), Role::Member);

        $task->describe('Plain text');
        $task->assign($membership);
        $task->dueOn(new \DateTimeImmutable('2026-12-01 15:30'));
        $task->prioritise(Priority::High);
        $task->moveTo(7);

        self::assertSame('Plain text', $task->description());
        self::assertSame($membership, $task->responsible());
        self::assertSame('2026-12-01 00:00', $task->dueDate()?->format('Y-m-d H:i'));
        self::assertSame(Priority::High, $task->priority());
        self::assertSame(7, $task->position());

        $task->assign(null);
        $task->dueOn(null);
        self::assertNull($task->responsible());
        self::assertNull($task->dueDate());
    }

    public function testAWatcherIsRecordedUnderItsTask(): void
    {
        $task = $this->task();
        $membership = new Membership(new User('testing@example.com', 'hash', 'Test Person'), Role::Member);
        $watcher = new TaskWatcher($task, $membership);

        self::assertSame($task, $watcher->auditRoot());
        self::assertSame($membership, $watcher->membership());
    }

    private function task(string $title = 'Task'): Task
    {
        return new Task($this->project, $title, $this->open, 0, Id::generate(), new \DateTimeImmutable('2026-11-07 08:00'));
    }

    private function project(): Project
    {
        return new Project('Website', null, WorkflowTemplate::Simple, Id::generate(), new \DateTimeImmutable('2026-11-07 08:00'));
    }
}
