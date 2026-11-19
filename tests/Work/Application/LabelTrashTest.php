<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Application;

use Foresi\Work\Application\LabelBadge;
use Foresi\Work\Application\PurgeLabelTrashDaily;
use Foresi\Work\Application\PurgeTrashedLabels;
use Foresi\Work\Application\TrashedLabel;
use Foresi\Work\Application\WorkRefused;
use Foresi\Work\Domain\Label;
use Foresi\Work\Domain\LabelColour;
use Foresi\Work\Domain\Task;
use Foresi\Work\Domain\TaskLabel;

/**
 * The trash of labels: a deleted label keeps its tasks but shows on none and
 * frees its name, comes back on the same tasks unless the name was taken
 * meanwhile, and after 30 days leaves its tasks for good.
 */
final class LabelTrashTest extends WorkTestCase
{
    private Task $task;

    private Task $other;

    protected function setUp(): void
    {
        parent::setUp();
        $project = $this->project();
        $this->task = $this->tasks->create($project->id(), 'Write the brief');
        $this->other = $this->tasks->create($project->id(), 'Draw the logo');
    }

    public function testADeletedLabelKeepsItsTasksButShowsNowhere(): void
    {
        $urgent = $this->labels->create('Urgent', LabelColour::Red);
        $soon = $this->labels->create('Soon', LabelColour::Amber);
        $this->tasks->setLabels($this->task->id(), [$urgent->id(), $soon->id()]);
        $this->tasks->setLabels($this->other->id(), [$urgent->id()]);
        $this->clock->sleep(60);

        $trashed = $this->labels->delete($urgent->id());

        self::assertSame(['2026-11-10 09:01', $this->owner->user()->id()], [$trashed->deletedAt()?->format('Y-m-d H:i'), $trashed->deletedBy()]);
        self::assertCount(3, $this->em->getRepository(TaskLabel::class)->findAll());
        self::assertSame(['Soon'], $this->badges($this->task));
        self::assertSame([], $this->badges($this->other));
        self::assertSame(['Soon'], array_map(static fn (LabelBadge $label): string => $label->name, $this->queries->labels()));
        self::assertSame(
            [[$urgent->id(), 'Urgent', LabelColour::Red, 'Olga Owner', '2026-11-10 09:01']],
            array_map(static fn (TrashedLabel $label): array => [$label->id, $label->name, $label->colour, $label->deletedByName, $label->deletedAt->format('Y-m-d H:i')], $this->queries->labelTrash()),
        );
        self::assertSame(2, $this->queries->labelUsage()[$urgent->id()] ?? null);
        // Gone for every command too.
        $this->assertRefused('unknown', fn (): Label => $this->labels->delete($urgent->id()));
        $this->assertRefused('unknown', fn () => $this->labels->rename($urgent->id(), 'Later'));
        $this->assertRefused('unknown', fn () => $this->tasks->setLabels($this->task->id(), [$urgent->id()]));
    }

    public function testChangingTheLabelsOfATaskKeepsTheOneInTheTrash(): void
    {
        $urgent = $this->labels->create('Urgent', LabelColour::Red);
        $soon = $this->labels->create('Soon', LabelColour::Amber);
        $this->tasks->setLabels($this->task->id(), [$urgent->id(), $soon->id()]);
        $this->labels->delete($urgent->id());

        $this->tasks->setLabels($this->task->id(), []);
        $this->labels->restore($urgent->id());

        self::assertSame(['Urgent'], $this->badges($this->task));
    }

    public function testARestoredLabelIsBackOnTheSameTasks(): void
    {
        $urgent = $this->labels->create('Urgent', LabelColour::Red);
        $this->tasks->setLabels($this->task->id(), [$urgent->id()]);
        $this->tasks->setLabels($this->other->id(), [$urgent->id()]);
        $this->labels->delete($urgent->id());

        $restored = $this->labels->restore($urgent->id());

        self::assertFalse($restored->isDeleted());
        self::assertSame([null, null], [$restored->deletedAt(), $restored->deletedBy()]);
        self::assertSame(['Urgent'], $this->badges($this->task));
        self::assertSame(['Urgent'], $this->badges($this->other));
        self::assertSame([], $this->queries->labelTrash());
        $this->assertRefused('unknown', fn (): Label => $this->labels->restore($urgent->id()));
        // Its name is taken again.
        $this->assertRefused('label_taken', fn (): Label => $this->labels->create('urgent', LabelColour::Gray));
    }

    public function testTheNameIsFreeInTheTrashAndARestoreIsRefusedOnceTaken(): void
    {
        $urgent = $this->labels->create('Urgent', LabelColour::Red);
        $this->labels->delete($urgent->id());

        $again = $this->labels->create('URGENT', LabelColour::Amber);

        $this->assertRefused('label_restore_taken', fn (): Label => $this->labels->restore($urgent->id()));
        $this->labels->rename($again->id(), 'Very urgent');
        $this->labels->restore($urgent->id());
        self::assertSame(['Urgent', 'Very urgent'], array_map(static fn (LabelBadge $label): string => $label->name, $this->queries->labels()));
    }

    public function testOnlyTheStaffRestoreAndSeeTheTrash(): void
    {
        $urgent = $this->labels->create('Urgent', LabelColour::Red);
        $this->labels->delete($urgent->id());
        $this->signIn($this->guest);

        $this->assertRefused('not_allowed', fn (): Label => $this->labels->restore($urgent->id()));
        self::assertSame([], $this->queries->labelTrash());
    }

    public function testALabelInTheTrashForMoreThan30DaysLeavesItsTasksForGoodByTheDailyJob(): void
    {
        $old = $this->labels->create('Old', LabelColour::Gray);
        $this->tasks->setLabels($this->task->id(), [$old->id()]);
        $this->labels->delete($old->id());
        $this->clock->sleep(10 * 86_400);
        $recent = $this->labels->create('Recent', LabelColour::Green);
        $this->tasks->setLabels($this->other->id(), [$recent->id()]);
        $this->labels->delete($recent->id());
        $live = $this->labels->create('Live', LabelColour::Red);
        $this->clock->sleep(21 * 86_400);

        $job = new PurgeLabelTrashDaily(self::getContainer()->get(PurgeTrashedLabels::class), $this->clock);
        self::assertSame('label-trash-purge', $job->name());
        $job->run(null);

        $this->em->clear();
        self::assertNull($this->em->find(Label::class, $old->id()));
        self::assertTrue($this->em->find(Label::class, $recent->id())?->isDeleted());
        self::assertFalse($this->em->find(Label::class, $live->id())?->isDeleted());
        self::assertSame([$recent->id()], array_map(static fn (TaskLabel $link): string => $link->label()->id(), $this->em->getRepository(TaskLabel::class)->findAll()));
        self::assertSame(0, self::getContainer()->get(PurgeTrashedLabels::class)->run($this->clock->now()));
    }

    /**
     * @return list<string>
     */
    private function badges(Task $task): array
    {
        return array_map(static fn (LabelBadge $label): string => $label->name, $this->queries->task($task->id())?->task->labels ?? []);
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
