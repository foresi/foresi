<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Work\Application;

use App\Audit\Application\AuditCorrelation;
use App\Audit\Application\AuditTrail;
use App\Shared\Domain\Id;
use App\Work\Application\WorkRefused;
use App\Work\Domain\Label;
use App\Work\Domain\LabelColour;
use App\Work\Domain\Priority;
use App\Work\Domain\Section;
use App\Work\Domain\StatusType;
use App\Work\Domain\Task;
use App\Work\Domain\WorkflowTemplate;

final class WorkRulesTest extends WorkTestCase
{
    public function testOnlyStaffKeepLabels(): void
    {
        $urgent = $this->labels->create('Urgent', LabelColour::Red);
        $this->signIn($this->guest);

        $this->assertRefused('not_allowed', fn (): Label => $this->labels->create('Soon', LabelColour::Amber));
        $this->assertRefused('not_allowed', fn () => $this->labels->rename($urgent->id(), 'Soon'));
        $this->assertRefused('not_allowed', fn () => $this->labels->recolour($urgent->id(), LabelColour::Amber));
        $this->assertRefused('not_allowed', fn () => $this->labels->delete($urgent->id()));
    }

    public function testAnUnknownLabelIsRefused(): void
    {
        $this->assertRefused('unknown', fn () => $this->labels->rename(Id::generate(), 'Soon'));
        $this->assertRefused('unknown', fn () => $this->labels->delete(Id::generate()));
    }

    public function testARenamedLabelIsTrimmedCheckedAndSaved(): void
    {
        $this->labels->create('Urgent', LabelColour::Red);
        $soon = $this->labels->create('Soon', LabelColour::Amber);

        $this->assertRefused('label_taken', fn () => $this->labels->rename($soon->id(), '  URGENT '));
        $this->labels->rename($soon->id(), '  Later ');

        $this->em->clear();
        self::assertSame('Later', $this->em->find(Label::class, $soon->id())?->name());
    }

    public function testASectionIsKnownToStaffOnly(): void
    {
        $project = $this->project();
        $section = $this->sections->add($project->id(), 'Design');

        $this->assertRefused('unknown', fn () => $this->sections->rename(Id::generate(), 'Build'));
        $this->assertRefused('unknown', fn (): Task => $this->tasks->create(Id::generate(), 'Write the brief'));
        $this->signIn($this->guest);
        $this->assertRefused('unknown', fn () => $this->sections->rename($section->id(), 'Build'));
        $this->assertRefused('unknown', fn (): Task => $this->tasks->create($project->id(), 'Write the brief'));
    }

    public function testRemovingTheFirstSectionNumbersTheOthersAgain(): void
    {
        $project = $this->project();
        $design = $this->sections->add($project->id(), 'Design');
        $build = $this->sections->add($project->id(), 'Build');
        $test = $this->sections->add($project->id(), 'Test');

        $this->sections->remove($design->id());

        self::assertSame([0, 1], [$build->position(), $test->position()]);
        self::assertNull($this->em->find(Section::class, $design->id()));
    }

    public function testASectionMovedBeforeTheFirstGoesFirst(): void
    {
        $project = $this->project();
        $design = $this->sections->add($project->id(), 'Design');
        $build = $this->sections->add($project->id(), 'Build');
        $test = $this->sections->add($project->id(), 'Test');

        $this->sections->move($test->id(), -1);

        self::assertSame([0, 1, 2], [$test->position(), $design->position(), $build->position()]);
    }

    public function testATaskWithoutAStatusStartsInTheFirstOpenOne(): void
    {
        $project = $this->project();
        [$toDo] = $this->statuses($project);
        $this->workflow->add($project->id(), 'Backlog', StatusType::Active, 0);

        $task = $this->tasks->create($project->id(), 'Write the brief', priority: Priority::High);

        self::assertSame($toDo->id(), $task->status()->id());
        self::assertSame(Priority::High, $task->priority());
    }

    public function testAProjectKeepsItsDescription(): void
    {
        $project = $this->projects->create('Website', null, WorkflowTemplate::Simple, 'The new site');

        self::assertSame('The new site', $project->description());
    }

    public function testATaskDroppedInAColumnWithoutATargetGoesAfterItsLastTask(): void
    {
        $project = $this->project();
        [$toDo, $inProgress] = $this->statuses($project);
        $this->tasks->create($project->id(), 'A');
        $this->tasks->create($project->id(), 'B', statusId: $inProgress->id());
        $c = $this->tasks->create($project->id(), 'C');
        $d = $this->tasks->create($project->id(), 'D');

        $this->tasks->move($d->id(), $inProgress->id());

        self::assertSame([2, 3], [$d->position(), $c->position()]);
        self::assertSame($toDo->id(), $c->status()->id());
    }

    public function testATaskIsNotDroppedBeforeATaskOfAnotherLevel(): void
    {
        $project = $this->project();
        [$toDo] = $this->statuses($project);
        $first = $this->tasks->create($project->id(), 'First');
        $second = $this->tasks->create($project->id(), 'Second');
        $subtask = $this->tasks->create($project->id(), 'Subtask', parentId: $second->id());

        $this->assertRefused('unknown', fn () => $this->tasks->move($first->id(), $toDo->id(), $subtask->id()));
    }

    public function testOnlyKnownLabelsAreSet(): void
    {
        $task = $this->tasks->create($this->project()->id(), 'Write the brief');

        $this->assertRefused('unknown', fn () => $this->tasks->setLabels($task->id(), [Id::generate()]));
    }

    public function testAStatusIsRemovedOnlyIntoAnotherStatusOfItsProject(): void
    {
        $project = $this->project();
        [, $inProgress] = $this->statuses($project);
        [$elsewhere] = $this->statuses($this->project());

        $this->assertRefused('target_status', fn () => $this->workflow->remove($inProgress->id(), $elsewhere->id()));
        $this->assertRefused('target_status', fn () => $this->workflow->remove($inProgress->id(), Id::generate()));
    }

    public function testAChangeIsSavedUnderOneCorrelationOfWork(): void
    {
        $task = $this->tasks->create($this->project()->id(), 'Write the brief');

        $created = $this->trail()->rootHistory(Task::class, $task->id())[0]->correlationId();

        self::assertMatchesRegularExpression('/^work:\S+$/', (string) $created);
    }

    public function testAChangeJoinsTheCorrelationAlreadyRunning(): void
    {
        $task = $this->tasks->create($this->project()->id(), 'Write the brief');

        self::getContainer()->get(AuditCorrelation::class)->within('outer', fn () => $this->tasks->rename($task->id(), 'Write the short brief'));

        self::assertSame('outer', array_last($this->trail()->rootHistory(Task::class, $task->id()))?->correlationId());
    }

    public function testAWorkRefusalTellsWhy(): void
    {
        self::assertSame('The change is refused: name.', new WorkRefused('name')->getMessage());
        $this->assertRefused('parent', static fn (): mixed => WorkRefused::unlessNamed(static fn () => throw new WorkRefused('parent')));
        $this->assertRefused('name', static fn (): mixed => WorkRefused::unlessNamed(static fn () => throw new \DomainException('Too long.')));
    }

    private function trail(): AuditTrail
    {
        return self::getContainer()->get(AuditTrail::class);
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
