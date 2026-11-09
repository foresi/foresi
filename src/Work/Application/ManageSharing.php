<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

use App\Audit\Application\AuditActor;
use App\Identity\Application\People;
use App\Identity\Domain\Membership;
use App\Work\Domain\Project;
use App\Work\Domain\ProjectVisibility;
use App\Work\Domain\Section;
use App\Work\Domain\Task;
use App\Work\Domain\WorkShare;
use Psr\Clock\ClockInterface;

/**
 * Who sees what: sharing a project, a section or a task with a person of the
 * organisation, taking it back, opening a project to the organisation or to
 * the people chosen, making a section or a task private. Only the people of
 * the organisation do this, on what they see themselves; guests never. Each
 * change is one audited step.
 *
 * Whoever closes an item they would then no longer see is kept on it with a
 * share of their own, as the creator of a private item stays in it in Asana
 * and ClickUp; an owner sees everything and needs none.
 */
final readonly class ManageSharing
{
    public function __construct(
        private WorkShares $shares,
        private VisibleWork $work,
        private WorkVisibility $visibility,
        private People $people,
        private AuditActor $actor,
        private ClockInterface $clock,
        private WorkUnit $unit,
    ) {
    }

    /**
     * Sharing twice is sharing once.
     *
     * @throws WorkRefused
     */
    public function shareProject(string $projectId, string $membershipId): void
    {
        $this->share($this->work->project($projectId), $membershipId);
    }

    /**
     * @throws WorkRefused
     */
    public function shareSection(string $sectionId, string $membershipId): void
    {
        $this->share($this->work->section($sectionId), $membershipId);
    }

    /**
     * @throws WorkRefused
     */
    public function shareTask(string $taskId, string $membershipId): void
    {
        $this->share($this->work->task($taskId), $membershipId);
    }

    /**
     * Taking back what was not shared changes nothing.
     *
     * @throws WorkRefused
     */
    public function unshareProject(string $projectId, string $membershipId): void
    {
        $this->unshare($this->work->project($projectId), $membershipId);
    }

    /**
     * @throws WorkRefused
     */
    public function unshareSection(string $sectionId, string $membershipId): void
    {
        $this->unshare($this->work->section($sectionId), $membershipId);
    }

    /**
     * @throws WorkRefused
     */
    public function unshareTask(string $taskId, string $membershipId): void
    {
        $this->unshare($this->work->task($taskId), $membershipId);
    }

    /**
     * @throws WorkRefused
     */
    public function changeVisibility(string $projectId, ProjectVisibility $visibility): void
    {
        $project = $this->work->project($projectId);
        $this->work->assertSharer($project);
        $this->unit->run(function () use ($project, $visibility): void {
            $project->changeVisibility($visibility);
            $this->keepSeeing($project, fn (): bool => $this->visibility->canSeeProject($project));
        });
    }

    /**
     * @throws WorkRefused
     */
    public function makeSectionPrivate(string $sectionId, bool $private): void
    {
        $section = $this->work->section($sectionId);
        $this->work->assertSharer($section);
        $this->unit->run(function () use ($section, $private): void {
            $section->makePrivate($private);
            $this->keepSeeing($section, fn (): bool => $this->visibility->canSeeSection($section));
        });
    }

    /**
     * @throws WorkRefused
     */
    public function makeTaskPrivate(string $taskId, bool $private): void
    {
        $task = $this->work->task($taskId);
        $this->work->assertSharer($task);
        $this->unit->run(function () use ($task, $private): void {
            $task->makePrivate($private);
            $this->keepSeeing($task, fn (): bool => $this->visibility->canSeeTask($task));
        });
    }

    /**
     * @throws WorkRefused
     */
    private function share(Project|Section|Task $item, string $membershipId): void
    {
        $this->work->assertSharer($item);
        $membership = $this->people->membership($membershipId);
        if (!$membership instanceof Membership || !$membership->isActive()) {
            throw new WorkRefused('share_person');
        }
        if ($this->shares->find($item, $membership) instanceof WorkShare) {
            return;
        }
        $this->unit->run(fn () => $this->shares->add(WorkShare::of($item, $membership, $this->clock->now())));
    }

    /**
     * @throws WorkRefused
     */
    private function unshare(Project|Section|Task $item, string $membershipId): void
    {
        $this->work->assertSharer($item);
        $membership = $this->people->membership($membershipId);
        $share = $membership instanceof Membership ? $this->shares->find($item, $membership) : null;
        if ($share instanceof WorkShare) {
            $this->unit->run(fn () => $this->shares->remove($share));
        }
    }

    /**
     * Shares the item with the signed-in person when they would lose sight of it.
     *
     * @param \Closure(): bool $sees whether they see it after the change
     */
    private function keepSeeing(Project|Section|Task $item, \Closure $sees): void
    {
        $userId = $this->actor->userId();
        $me = null === $userId ? null : $this->people->membershipOf($userId);
        if ($me instanceof Membership && !$sees() && !$this->shares->find($item, $me) instanceof WorkShare) {
            $this->shares->add(WorkShare::of($item, $me, $this->clock->now()));
        }
    }
}
