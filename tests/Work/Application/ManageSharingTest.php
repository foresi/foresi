<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Application;

use Foresi\Audit\Application\AuditTrail;
use Foresi\Audit\Domain\AuditAction;
use Foresi\Audit\Domain\AuditEntry;
use Foresi\Shared\Domain\Id;
use Foresi\Work\Application\ManageSharing;
use Foresi\Work\Application\ProjectRow;
use Foresi\Work\Application\WorkRefused;
use Foresi\Work\Domain\Project;
use Foresi\Work\Domain\ProjectVisibility;
use Foresi\Work\Domain\Section;
use Foresi\Work\Domain\Task;
use Foresi\Work\Domain\WorkShare;

final class ManageSharingTest extends WorkTestCase
{
    private ManageSharing $sharing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sharing = self::getContainer()->get(ManageSharing::class);
    }

    public function testASharedProjectOpensToTheChosenPersonAndClosesWhenTakenBack(): void
    {
        $project = $this->project();
        $this->sharing->changeVisibility($project->id(), ProjectVisibility::Chosen);
        $this->signIn($this->member);
        self::assertSame([], $this->queries->projects());

        $this->signIn($this->owner);
        $this->sharing->shareProject($project->id(), $this->member->id());
        $this->sharing->shareProject($project->id(), $this->member->id());
        self::assertSame(1, $this->shareCount());
        $this->signIn($this->member);
        self::assertSame(['Website'], array_map(static fn (ProjectRow $row): string => $row->name, $this->queries->projects()));

        $this->signIn($this->owner);
        $this->sharing->unshareProject($project->id(), $this->member->id());
        $this->sharing->unshareProject($project->id(), $this->member->id());
        $this->signIn($this->member);
        self::assertSame([], $this->queries->projects());
        self::assertSame(0, $this->shareCount());

        $this->em->clear();
        self::assertSame(ProjectVisibility::Chosen, $this->em->find(Project::class, $project->id())?->visibility());
    }

    public function testEachKindOfItemIsSharedAndTakenBackOnItsOwn(): void
    {
        $project = $this->project();
        $section = $this->sections->add($project->id(), 'Design');
        $task = $this->tasks->create($project->id(), 'Write the brief');

        $this->sharing->shareProject($project->id(), $this->guest->id());
        $this->sharing->shareSection($section->id(), $this->guest->id());
        $this->sharing->shareTask($task->id(), $this->guest->id());
        self::assertSame(3, $this->shareCount());

        $this->sharing->unshareSection($section->id(), $this->guest->id());
        $this->sharing->unshareTask($task->id(), $this->guest->id());
        $this->sharing->unshareTask($task->id(), Id::generate());
        $shares = $this->em->getRepository(WorkShare::class)->findAll();
        self::assertCount(1, $shares);
        self::assertSame($project->id(), $shares[0]->project()?->id());
        self::assertSame($this->clock->now()->format('c'), $shares[0]->createdAt()->format('c'));
    }

    public function testOnlyThePeopleOfTheOrganisationShareAndOnlyWhatTheySee(): void
    {
        $project = $this->project();
        $section = $this->sections->add($project->id(), 'Design');
        $task = $this->tasks->create($project->id(), 'Write the brief');
        $this->sharing->shareProject($project->id(), $this->guest->id());

        // A guest sees the project, but never shares or closes anything.
        $this->signIn($this->guest);
        $this->assertRefused('not_allowed', fn () => $this->sharing->shareTask($task->id(), $this->member->id()));
        $this->assertRefused('not_allowed', fn () => $this->sharing->unshareProject($project->id(), $this->guest->id()));
        $this->assertRefused('not_allowed', fn () => $this->sharing->changeVisibility($project->id(), ProjectVisibility::Chosen));
        $this->assertRefused('not_allowed', fn () => $this->sharing->makeSectionPrivate($section->id(), true));
        $this->assertRefused('not_allowed', fn () => $this->sharing->makeTaskPrivate($task->id(), true));

        // A member does not share what is hidden from them.
        $this->signIn($this->owner);
        $this->sharing->makeTaskPrivate($task->id(), true);
        $this->sharing->makeSectionPrivate($section->id(), true);
        $this->signIn($this->member);
        $this->assertRefused('unknown', fn () => $this->sharing->shareTask($task->id(), $this->guest->id()));
        $this->assertRefused('unknown', fn () => $this->sharing->shareSection($section->id(), $this->guest->id()));
        $this->assertRefused('unknown', fn () => $this->sharing->shareProject(Id::generate(), $this->guest->id()));
        $this->sharing->shareProject($project->id(), $this->owner->id());
    }

    public function testSharingIsWithAnActivePerson(): void
    {
        $task = $this->tasks->create($this->project()->id(), 'Write the brief');

        $this->assertRefused('share_person', fn () => $this->sharing->shareTask($task->id(), Id::generate()));
        $this->guest->deactivate($this->clock->now());
        $this->em->flush();
        $this->assertRefused('share_person', fn () => $this->sharing->shareTask($task->id(), $this->guest->id()));
        self::assertSame(0, $this->shareCount());
    }

    public function testWhoClosesAnItemKeepsSeeingItAndAnOwnerNeedsNoShare(): void
    {
        $project = $this->project();
        $section = $this->sections->add($project->id(), 'Design');
        $task = $this->tasks->create($project->id(), 'Write the brief');

        $this->sharing->makeTaskPrivate($task->id(), true);
        self::assertSame(0, $this->shareCount());

        $this->signIn($this->member);
        $this->sharing->changeVisibility($project->id(), ProjectVisibility::Chosen);
        $this->sharing->makeSectionPrivate($section->id(), true);
        $this->sharing->makeSectionPrivate($section->id(), true);
        self::assertSame(2, $this->shareCount());
        self::assertNotNull($this->queries->projectList($project->id()));
        self::assertNull($this->queries->task($task->id()));

        $this->signIn($this->owner);
        $this->sharing->makeTaskPrivate($task->id(), false);
        $this->sharing->makeSectionPrivate($section->id(), false);
        $this->sharing->changeVisibility($project->id(), ProjectVisibility::Organisation);
        $this->em->clear();
        self::assertFalse($this->em->find(Task::class, $task->id())?->isPrivate());
        self::assertFalse($this->em->find(Section::class, $section->id())?->isPrivate());
        self::assertSame(ProjectVisibility::Organisation, $this->em->find(Project::class, $project->id())?->visibility());
    }

    public function testSharesAreAuditedUnderTheTaskOrTheProject(): void
    {
        $project = $this->project();
        $section = $this->sections->add($project->id(), 'Design');
        $task = $this->tasks->create($project->id(), 'Write the brief');

        $this->sharing->shareTask($task->id(), $this->guest->id());
        $this->sharing->shareSection($section->id(), $this->guest->id());
        $this->sharing->makeTaskPrivate($task->id(), true);
        $this->sharing->unshareTask($task->id(), $this->guest->id());

        $trail = self::getContainer()->get(AuditTrail::class);
        $describe = static fn (AuditEntry $entry): string => basename(str_replace('\\', '/', $entry->entityClass())).' '.$entry->action()->value;
        self::assertSame(['Task created', 'TaskWatcher created', 'WorkShare created', 'Task updated', 'WorkShare deleted'], array_map($describe, $trail->rootHistory(Task::class, $task->id())));
        $projectLog = array_map($describe, $trail->rootHistory(Project::class, $project->id()));
        self::assertContains('WorkShare created', $projectLog);
        self::assertNotContains(AuditAction::Deleted->value, $projectLog);
    }

    public function testRemovingAPrivateSectionKeepsItsTasksHiddenFromTheOthers(): void
    {
        $project = $this->project();
        $section = $this->sections->add($project->id(), 'Design');
        $task = $this->tasks->create($project->id(), 'Write the brief', sectionId: $section->id());
        $subtask = $this->tasks->create($project->id(), 'Find a font', sectionId: $section->id(), parentId: $task->id());
        $this->sharing->makeSectionPrivate($section->id(), true);
        $this->sharing->shareSection($section->id(), $this->guest->id());

        $this->sections->remove($section->id());

        self::assertTrue($task->isPrivate());
        self::assertFalse($subtask->isPrivate());
        $shares = $this->em->getRepository(WorkShare::class)->findAll();
        self::assertSame([[$task->id(), $this->guest->id()]], array_map(static fn (WorkShare $share): array => [$share->task()?->id(), $share->membership()->id()], $shares));
        $this->signIn($this->member);
        self::assertNull($this->queries->task($task->id()));
        $this->signIn($this->guest);
        self::assertNotNull($this->queries->task($subtask->id()));
    }

    private function shareCount(): int
    {
        return \count($this->em->getRepository(WorkShare::class)->findAll());
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
