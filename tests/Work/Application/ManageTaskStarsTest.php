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
use Foresi\Work\Application\TaskRow;
use Foresi\Work\Application\TaskSearch;
use Foresi\Work\Application\View\TaskLists;
use Foresi\Work\Application\WorkRefused;
use Foresi\Work\Domain\Project;
use Foresi\Work\Domain\ProjectVisibility;
use Foresi\Work\Domain\StatusType;
use Foresi\Work\Domain\Task;

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

        foreach ([[$a->id(), $b->id()], [$a->id(), $a->id(), $b->id(), $c->id()]] as $wrong) {
            try {
                $this->stars->reorder($wrong);
                self::fail('Not every starred task named once.');
            } catch (WorkRefused $refused) {
                self::assertSame('star_order', $refused->reason);
            }
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
