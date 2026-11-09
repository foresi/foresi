<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Work\Application;

use App\Audit\Application\AuditTrail;
use App\Audit\Domain\AuditAction;
use App\Audit\Domain\AuditEntry;
use App\Identity\Domain\Membership;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Shared\Domain\Id;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\Tenant;
use App\Tenancy\Domain\TenantId;
use App\Work\Application\ManageSharing;
use App\Work\Application\ProjectRow;
use App\Work\Application\WorkRefused;
use App\Work\Domain\Project;
use App\Work\Domain\ProjectVisibility;
use App\Work\Domain\Section;
use App\Work\Domain\Task;
use App\Work\Domain\WorkShare;

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

    public function testSharingIsWithAnActivePersonOfThisOrganisation(): void
    {
        $task = $this->tasks->create($this->project()->id(), 'Write the brief');
        $stranger = $this->strangerOfAnotherOrganisation();

        $this->assertRefused('share_person', fn () => $this->sharing->shareTask($task->id(), Id::generate()));
        $this->assertRefused('share_person', fn () => $this->sharing->shareTask($task->id(), $stranger));
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

    /**
     * The membership of a person in another organisation; this one does not know it.
     */
    private function strangerOfAnotherOrganisation(): string
    {
        $context = self::getContainer()->get(TenantContext::class);
        $home = $context->current();
        $other = new Tenant(TenantId::generate(), 'Other Organisation', $this->clock->now());
        $this->em->persist($other);
        $this->em->flush();
        $context->switchTo($other->id());
        $user = new User('stranger@example.com', 'hash', 'Stella Stranger');
        $membership = new Membership($user, Role::Member);
        $this->em->persist($user);
        $this->em->persist($membership);
        $this->em->flush();
        $context->switchTo($home);
        foreach (['owner', 'member', 'guest'] as $person) {
            $this->{$person} = $this->em->find(Membership::class, $this->{$person}->id()) ?? throw new \LogicException('The person is gone.');
        }

        return $membership->id();
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
