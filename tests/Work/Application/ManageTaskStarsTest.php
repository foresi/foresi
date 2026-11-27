<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Application;

use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Work\Application\ManageSharing;
use Foresi\Work\Application\ManageTaskStars;
use Foresi\Work\Application\PurgeDeletedTasks;
use Foresi\Work\Application\TaskRow;
use Foresi\Work\Application\TaskSearch;
use Foresi\Work\Application\TaskStars;
use Foresi\Work\Application\View\TaskLists;
use Foresi\Work\Application\WorkRefused;
use Foresi\Work\Domain\Project;
use Foresi\Work\Domain\ProjectVisibility;
use Foresi\Work\Domain\StatusType;
use Foresi\Work\Domain\Task;
use Foresi\Work\Domain\TaskStar;

/**
 * My priorities (plan 37.2): a person stars the tasks they see, a new star goes to the end,
 * the order is theirs to change, nobody else sees their stars, a star stays through a new
 * responsible and a new due date, and a finished task leaves the list for good.
 */
final class ManageTaskStarsTest extends WorkTestCase
{
    private ManageTaskStars $stars;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stars = self::getContainer()->get(ManageTaskStars::class);
        $this->project = $this->project();
    }

    public function testANewStarGoesToTheEndAndStarringAgainKeepsItsPlace(): void
    {
        [$a, $b, $c] = $this->tasks('Write the brief', 'Call the client', 'Plan the launch');

        $this->stars->star($c->id());
        $this->stars->star($a->id());
        $this->stars->star($b->id());
        $this->stars->star($c->id());

        self::assertSame(['Plan the launch', 'Write the brief', 'Call the client'], $this->priorities());
        $rows = $this->queries->taskIndex(new TaskSearch(new ViewFilter(), sort: TaskLists::SORT_TITLE), 0, 10)->rows;
        self::assertSame([true, true, true], array_map(static fn (TaskRow $row): bool => $row->starred, $rows));
    }

    public function testTheOrderIsTheOnePersonGives(): void
    {
        [$a, $b, $c] = $this->tasks('Write the brief', 'Call the client', 'Plan the launch');
        foreach ([$a, $b, $c] as $task) {
            $this->stars->star($task->id());
        }

        $this->stars->move($c->id(), $a->id());
        self::assertSame(['Plan the launch', 'Write the brief', 'Call the client'], $this->priorities());
        $this->stars->move($c->id(), null);
        self::assertSame(['Write the brief', 'Call the client', 'Plan the launch'], $this->priorities());
        $this->stars->star($b->id(), $a->id());
        self::assertSame(['Call the client', 'Write the brief', 'Plan the launch'], $this->priorities());

        $this->stars->reorder([$c->id(), $a->id(), $b->id()]);
        self::assertSame(['Plan the launch', 'Write the brief', 'Call the client'], $this->priorities());

        foreach ([[$a->id(), $b->id()], [$a->id(), $a->id(), $b->id()], [$a->id(), $a->id(), $b->id(), $c->id()]] as $wrong) {
            try {
                $this->stars->reorder($wrong);
                self::fail('Not every starred task named once.');
            } catch (WorkRefused $refused) {
                self::assertSame('star_order', $refused->reason);
            }
        }
    }

    public function testTheStarsAreNumberedFromZeroInTheirOrder(): void
    {
        [$a, $b, $c] = $this->tasks('Write the brief', 'Call the client', 'Plan the launch');
        foreach ([$a, $b, $c] as $task) {
            $this->stars->star($task->id());
        }
        self::assertSame([0, 1, 2], $this->positions());

        // A task moved down goes just before the one named, counted among the others.
        $this->stars->move($a->id(), $c->id());
        self::assertSame(['Call the client', 'Write the brief', 'Plan the launch'], $this->priorities());
        self::assertSame([0, 1, 2], $this->positions());
    }

    public function testOnlyAStarredTaskMovesAndOnlyBeforeAStarredOne(): void
    {
        [$a, $b] = $this->tasks('Write the brief', 'Call the client');
        $this->stars->star($a->id());

        foreach ([fn () => $this->stars->move($b->id(), null), fn () => $this->stars->move($a->id(), $b->id())] as $move) {
            try {
                $move();
                self::fail('A move of a task without a star, or before one.');
            } catch (WorkRefused $refused) {
                self::assertSame('unknown', $refused->reason);
            }
        }
    }

    public function testATaskInTheTrashKeepsItsStarOutOfTheList(): void
    {
        [$a, $b, $c] = $this->tasks('Write the brief', 'Call the client', 'Plan the launch');
        foreach ([$a, $b, $c] as $task) {
            $this->stars->star($task->id());
        }

        $this->tasks->delete($a->id());
        $this->stars->reorder([$c->id(), $b->id()]);

        self::assertSame(['Plan the launch', 'Call the client'], $this->priorities());
        // The star in the trash follows the ones the list shows.
        self::assertSame([$c->id(), $b->id(), $a->id()], array_map(static fn (TaskStar $star): string => $star->task()->id(), self::getContainer()->get(TaskStars::class)->of($this->owner)));
    }

    public function testOneWhoLeftTheFirmHasNoPriorities(): void
    {
        $this->member->deactivate($this->clock->now());
        $this->em->flush();
        $this->signIn($this->member);

        try {
            $this->stars->reorder([]);
            self::fail('One who left the firm orders stars.');
        } catch (WorkRefused $refused) {
            self::assertSame('unknown', $refused->reason);
        }
    }

    public function testAStarIsThePersonsOwn(): void
    {
        [$task] = $this->tasks('Write the brief');
        $this->stars->star($task->id());

        $this->signIn($this->member);
        self::assertSame([], $this->priorities());
        self::assertFalse($this->queries->task($task->id())?->task->starred);
        $this->stars->star($task->id());
        $this->stars->unstar($task->id());

        $this->signIn($this->owner);
        self::assertSame(['Write the brief'], $this->priorities());
        self::assertTrue($this->queries->task($task->id())?->task->starred);
    }

    public function testAStarStaysThroughANewResponsibleAndDueDate(): void
    {
        [$task] = $this->tasks('Write the brief');
        $this->stars->star($task->id());

        $this->tasks->assign($task->id(), $this->member->id());
        $this->tasks->setDue($task->id(), new \DateTimeImmutable('2026-12-01'));

        self::assertSame(['Write the brief'], $this->priorities());
    }

    public function testAFinishedTaskLeavesTheListForGood(): void
    {
        [$task, $other] = $this->tasks('Write the brief', 'Call the client');
        $this->stars->star($task->id());
        $this->stars->star($other->id());
        [$open, $done] = $this->byType();

        $this->tasks->changeStatus($task->id(), $done);
        self::assertSame(['Call the client'], $this->priorities());
        $this->tasks->changeStatus($task->id(), $open);
        self::assertSame(['Call the client'], $this->priorities());

        $this->tasks->changeStatus($task->id(), $done);
        try {
            $this->stars->star($task->id());
            self::fail('A finished task is starred.');
        } catch (WorkRefused $refused) {
            self::assertSame('star_finished', $refused->reason);
        }
    }

    public function testAStatusRetypedToFinishedTakesTheStarsOfItsTasks(): void
    {
        [$a, $b] = $this->tasks('Write the brief', 'Call the client');
        [$toDo, $inProgress] = $this->statuses($this->project);
        $this->tasks->changeStatus($b->id(), $inProgress->id());
        $this->stars->star($a->id());
        $this->stars->star($b->id());

        // A status retyped among the open types keeps the stars.
        $this->workflow->retype($inProgress->id(), StatusType::Open);
        self::assertSame(['Write the brief', 'Call the client'], $this->starredTitles());

        $this->workflow->retype($toDo->id(), StatusType::Done);
        self::assertSame(['Call the client'], $this->starredTitles());
        // Open again, the task has no star.
        $this->workflow->retype($toDo->id(), StatusType::Open);
        self::assertSame(['Call the client'], $this->starredTitles());
    }

    public function testAStatusRemovedIntoAFinishedOneTakesTheStarsOfItsTasks(): void
    {
        [$a, $b] = $this->tasks('Write the brief', 'Call the client');
        [$toDo, $inProgress, $done] = $this->statuses($this->project);
        $this->workflow->add($this->project->id(), 'Backlog', StatusType::Open);
        $this->tasks->changeStatus($b->id(), $inProgress->id());
        $this->stars->star($a->id());
        $this->stars->star($b->id());

        $this->workflow->remove($inProgress->id(), $toDo->id());
        self::assertSame(['Write the brief', 'Call the client'], $this->starredTitles());

        $this->workflow->remove($toDo->id(), $done->id());
        self::assertSame([], $this->starredTitles());
    }

    public function testThePurgeTakesTheStarsOfTheTasksItRemoves(): void
    {
        [$a, $b] = $this->tasks('Write the brief', 'Call the client');
        $this->stars->star($a->id());
        $this->stars->star($b->id());
        $this->tasks->delete($a->id());

        self::assertSame(1, self::getContainer()->get(PurgeDeletedTasks::class)->run($this->clock->now()->modify('+31 days')));
        $this->em->clear();
        self::assertSame(['Call the client'], $this->starredTitles());
    }

    public function testATaskOutOfSightKeepsItsPlaceOutOfTheList(): void
    {
        [$a, $b] = $this->tasks('Write the brief', 'Call the client');
        $this->signIn($this->member);
        $this->stars->star($a->id());
        $this->stars->star($b->id());

        $this->signIn($this->owner);
        self::getContainer()->get(ManageSharing::class)->changeVisibility($this->project->id(), ProjectVisibility::Chosen);
        $this->signIn($this->member);
        self::assertSame([], $this->priorities());
        // The order names only what the list shows.
        $this->stars->reorder([]);
    }

    /**
     * @return list<Task>
     */
    private function tasks(string ...$titles): array
    {
        return array_map(fn (string $title): Task => $this->tasks->create($this->project->id(), $title), array_values($titles));
    }

    /**
     * @return list<string> the titles of the owner's stars in order, finished and out of sight too
     */
    private function starredTitles(): array
    {
        return array_map(static fn (TaskStar $star): string => $star->task()->title(), self::getContainer()->get(TaskStars::class)->of($this->owner));
    }

    /**
     * @return list<int> the positions of the owner's stars, in order
     */
    private function positions(): array
    {
        return array_map(static fn (TaskStar $star): int => $star->position(), self::getContainer()->get(TaskStars::class)->of($this->owner));
    }

    /**
     * @return list<string> the titles of My priorities, in order
     */
    private function priorities(): array
    {
        $rows = $this->queries->taskIndex(new TaskSearch(ViewFilter::all(new Condition(TaskLists::STARRED, 'is', true)), sort: TaskLists::SORT_MY_ORDER), 0, 100)->rows;

        return array_map(static fn (TaskRow $row): string => $row->title, $rows);
    }

    /**
     * @return array{string, string} the first open and the first done status of the project
     */
    private function byType(): array
    {
        $open = $done = null;
        foreach ($this->statuses($this->project) as $status) {
            $open ??= StatusType::Open === $status->type() ? $status->id() : null;
            $done ??= StatusType::Done === $status->type() ? $status->id() : null;
        }

        return [$open ?? throw new \LogicException('Open.'), $done ?? throw new \LogicException('Done.')];
    }
}
