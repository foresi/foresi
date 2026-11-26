<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Integration\Activities;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Activities\Contract\ActivityRecorder;
use Foresi\Activities\Contract\RecordedActivity;
use Foresi\Activities\Domain\ActivityTiming;
use Foresi\Activities\Domain\ActivityType;
use Foresi\Shared\Domain\Id;
use Foresi\Tests\Work\Infrastructure\Api\WorkApiTestCase;
use Foresi\Work\Application\ProjectBudgetReads;
use Foresi\Work\Application\ProjectBudgets;
use Foresi\Work\Application\WorkRefused;
use Foresi\Work\Domain\Project;
use Foresi\Work\Domain\ProjectBudgetChange;

/**
 * The activity a budget change came from, such as the approving e-mail, with the Activities
 * module installed: it must be linked to the project the change is for.
 */
final class ProjectBudgetSourceTest extends WorkApiTestCase
{
    public function testASourceActivityMustBeLinkedToTheProject(): void
    {
        $project = $this->project();
        $other = $this->project('Other');
        $this->flushed(fn () => $this->service(EntityManagerInterface::class)->persist(new ActivityType('email', null, ActivityTiming::None, null, new \DateTimeImmutable())));
        $linked = $this->activity('Approved by e-mail', $project);

        $change = $this->as(fn (): ProjectBudgetChange => $this->budgets()->add($project->id(), 480, sourceActivityId: $linked));
        self::assertSame($linked, $change->sourceActivityId());
        self::assertSame(480, $this->budgetMinutes($project));

        self::assertSame('source_not_on_project', $this->refused(fn (): ProjectBudgetChange => $this->budgets()->add($other->id(), 120, sourceActivityId: $linked)));
        self::assertSame('source_not_on_project', $this->refused(fn (): ProjectBudgetChange => $this->budgets()->add($project->id(), 120, sourceActivityId: Id::generate())));
        self::assertNull($this->budgetMinutes($other));
    }

    private function budgetMinutes(Project $project): ?int
    {
        return $this->as(fn (): ?int => $this->service(ProjectBudgetReads::class)->budget($project->id())->budgetMinutes);
    }

    /**
     * @param \Closure(): mixed $change
     */
    private function refused(\Closure $change): string
    {
        try {
            $this->as($change);
        } catch (WorkRefused $refused) {
            return $refused->reason;
        }
        self::fail('Not refused.');
    }

    private function activity(string $subject, Project $project): string
    {
        return $this->as(fn (): string => $this->service(ActivityRecorder::class)->record(new RecordedActivity('email', $subject, '', new \DateTimeImmutable('2026-11-20 09:30:00', new \DateTimeZone('UTC')), $this->owner->id(), [['kind' => 'project', 'id' => $project->id()]])));
    }

    private function budgets(): ProjectBudgets
    {
        return $this->service(ProjectBudgets::class);
    }
}
