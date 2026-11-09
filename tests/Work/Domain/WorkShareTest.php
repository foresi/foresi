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
use App\Work\Domain\Project;
use App\Work\Domain\ProjectStatus;
use App\Work\Domain\ProjectVisibility;
use App\Work\Domain\Section;
use App\Work\Domain\StatusType;
use App\Work\Domain\Task;
use App\Work\Domain\WorkflowTemplate;
use App\Work\Domain\WorkShare;
use PHPUnit\Framework\TestCase;

final class WorkShareTest extends TestCase
{
    public function testNewWorkIsOpenToTheOrganisationAndNothingIsPrivate(): void
    {
        [$project, $section, $task] = $this->work();

        self::assertSame(ProjectVisibility::Organisation, $project->visibility());
        self::assertFalse($section->isPrivate());
        self::assertFalse($task->isPrivate());

        $project->changeVisibility(ProjectVisibility::Chosen);
        $section->makePrivate(true);
        $task->makePrivate(true);
        self::assertSame(ProjectVisibility::Chosen, $project->visibility());
        self::assertTrue($section->isPrivate());
        self::assertTrue($task->isPrivate());
    }

    public function testAShareIsOfExactlyOneItemAndAuditedUnderTheTaskOrTheProject(): void
    {
        [$project, $section, $task] = $this->work();
        $membership = new Membership(new User('guest@example.com', 'hash', 'Greta Guest'), Role::Member);
        $now = new \DateTimeImmutable('2026-11-09 09:00');

        $ofProject = WorkShare::of($project, $membership, $now);
        $ofSection = WorkShare::of($section, $membership, $now);
        $ofTask = WorkShare::of($task, $membership, $now);

        self::assertSame([$project, null, null], [$ofProject->project(), $ofProject->section(), $ofProject->task()]);
        self::assertSame([null, $section, null], [$ofSection->project(), $ofSection->section(), $ofSection->task()]);
        self::assertSame([null, null, $task], [$ofTask->project(), $ofTask->section(), $ofTask->task()]);
        self::assertSame([$project, $project, $task], [$ofProject->auditRoot(), $ofSection->auditRoot(), $ofTask->auditRoot()]);
        self::assertSame([$project, $project, $project], [$ofProject->projectOfItem(), $ofSection->projectOfItem(), $ofTask->projectOfItem()]);
        self::assertSame($membership, $ofTask->membership());
        self::assertSame($now, $ofTask->createdAt());
        self::assertTrue(Id::isValid($ofTask->id()));
        self::assertNotSame($ofTask->id(), $ofSection->id());

        $this->expectException(\LogicException::class);
        $ofTask->tenantId();
    }

    /**
     * @return array{Project, Section, Task}
     */
    private function work(): array
    {
        $project = new Project('Website', null, WorkflowTemplate::Simple, Id::generate(), new \DateTimeImmutable('2026-11-09 08:00'));
        $section = new Section($project, 'Design', 0);
        $task = new Task($project, 'Write the brief', new ProjectStatus($project, 'To do', StatusType::Open, 0), 0, Id::generate(), new \DateTimeImmutable('2026-11-09 08:00'), $section);

        return [$project, $section, $task];
    }
}
