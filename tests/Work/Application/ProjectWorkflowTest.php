<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Work\Application;

use App\Work\Application\SectionTasks;
use App\Work\Application\TaskRow;
use App\Work\Application\WorkRefused;
use App\Work\Domain\Label;
use App\Work\Domain\LabelColour;
use App\Work\Domain\Project;
use App\Work\Domain\ProjectStatus;
use App\Work\Domain\StatusType;
use App\Work\Domain\Task;
use App\Work\Domain\WorkflowTemplate;

final class ProjectWorkflowTest extends WorkTestCase
{
    public function testANewProjectSeedsTheStatusesOfItsTemplateInTheLanguageOfThePerson(): void
    {
        $project = $this->project(WorkflowTemplate::Development);

        self::assertSame(
            [['Otevřeno', StatusType::Open, 0], ['Probíhá', StatusType::Active, 1], ['Kontrola', StatusType::Active, 2], ['Testování', StatusType::Active, 3], ['Blokováno', StatusType::Active, 4], ['Hotovo', StatusType::Done, 5], ['Uzavřeno', StatusType::Closed, 6]],
            array_map(static fn (ProjectStatus $status): array => [$status->name(), $status->type(), $status->position()], $this->statuses($project)),
        );
        self::assertSame($this->owner->user()->id(), $project->createdBy());
    }

    public function testTheLastOpenAndTheLastDoneStatusStay(): void
    {
        $project = $this->project();
        [$toDo, , $done] = $this->statuses($project);

        $this->assertRefused('last_open', fn () => $this->workflow->remove($toDo->id(), $done->id()));
        $this->assertRefused('last_done', fn () => $this->workflow->retype($done->id(), StatusType::Closed));
        $this->assertRefused('target_status', fn () => $this->workflow->remove($done->id(), $done->id()));
    }

    public function testTheTasksOfARemovedStatusMoveToTheChosenOne(): void
    {
        $project = $this->project();
        [$toDo, $inProgress] = $this->statuses($project);
        $task = $this->tasks->create($project->id(), 'Write the brief');
        $backlog = $this->workflow->add($project->id(), 'Backlog', StatusType::Open, 0);
        self::assertSame($toDo->id(), $task->status()->id());

        $this->workflow->remove($toDo->id(), $inProgress->id());

        self::assertSame(['Backlog', 'Probíhá', 'Hotovo'], array_map(static fn (ProjectStatus $status): string => $status->name(), $this->statuses($project)));
        self::assertSame([0, 1, 2], array_map(static fn (ProjectStatus $status): int => $status->position(), $this->statuses($project)));
        self::assertSame($backlog->id(), $this->statuses($project)[0]->id());
        self::assertSame($inProgress->id(), $this->em->find(Task::class, $task->id())?->status()->id());
    }

    public function testARemovedSectionLeavesItsTasksInNoSection(): void
    {
        $project = $this->project();
        $design = $this->sections->add($project->id(), 'Design');
        $build = $this->sections->add($project->id(), 'Build');
        $this->tasks->create($project->id(), 'Sketch', sectionId: $design->id());
        $this->sections->move($build->id(), 0);

        $this->sections->remove($design->id());

        $list = $this->queries->projectList($project->id());
        self::assertNotNull($list);
        self::assertSame([[null, ['Sketch']], ['Build', []]], array_map(
            static fn (SectionTasks $section): array => [$section->name, array_map(static fn (TaskRow $row): string => $row->title, $section->tasks)],
            $list->sections,
        ));
        self::assertSame(0, $build->position());
    }

    public function testALabelNameIsTakenWhateverItsCase(): void
    {
        $this->labels->create('Urgent', LabelColour::Red);

        $this->assertRefused('label_taken', fn (): Label => $this->labels->create(' urgent ', LabelColour::Amber));
    }

    public function testAGuestSeesNoWorkAndChangesNone(): void
    {
        $project = $this->project();
        $task = $this->tasks->create($project->id(), 'Write the brief');
        $this->signIn($this->guest);

        self::assertSame([], $this->queries->projects());
        self::assertNull($this->queries->board($project->id()));
        self::assertNull($this->queries->task($task->id()));
        $this->assertRefused('unknown', fn () => $this->tasks->rename($task->id(), 'Mine now'));
        $this->assertRefused('not_allowed', fn (): Project => $this->projects->create('Mine', null, WorkflowTemplate::Simple));
    }

    private function assertRefused(string $reason, callable $change): void
    {
        try {
            $change();
            self::fail(sprintf('Expected the refusal "%s".', $reason));
        } catch (WorkRefused $refused) {
            self::assertSame($reason, $refused->reason);
        }
    }
}
