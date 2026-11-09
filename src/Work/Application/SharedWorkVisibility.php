<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

use App\Audit\Application\AuditActor;
use App\Identity\Application\People;
use App\Identity\Domain\Membership;
use App\Identity\Domain\Role;
use App\Work\Domain\Project;
use App\Work\Domain\Section;
use App\Work\Domain\Task;

/**
 * The scope of a person by their membership of the active organisation: an
 * owner sees everything, a team member what is open to the organisation and
 * what is shared with them, a guest only what is shared with them. A person
 * whose membership is deactivated, or who has none, sees nothing.
 *
 * The shares are read again for every question, so a share given or taken
 * away counts at once.
 */
final readonly class SharedWorkVisibility implements WorkVisibility
{
    public function __construct(private People $people, private WorkShares $shares, private AuditActor $actor)
    {
    }

    public function canManage(): bool
    {
        return $this->me()?->role()->isStaff() ?? false;
    }

    public function canSeeProject(Project $project): bool
    {
        return $this->scope()->seesProject($project);
    }

    public function canSeeProjectName(Project $project): bool
    {
        return $this->scope()->knowsProject($project);
    }

    public function canSeeSection(Section $section): bool
    {
        return $this->scope()->seesSection($section);
    }

    public function canSeeTask(Task $task): bool
    {
        return $this->scope()->seesTask($task);
    }

    public function canCreateTaskIn(Project $project, ?Section $section): bool
    {
        return $this->scope()->allowsTaskIn($project, $section);
    }

    public function canComment(Task $task): bool
    {
        return $this->me() instanceof Membership && $this->canSeeTask($task);
    }

    public function canSeeInternal(): bool
    {
        return $this->canManage();
    }

    public function canShare(Project|Section|Task $item): bool
    {
        if (!$this->canManage()) {
            return false;
        }
        $scope = $this->scope();

        return match (true) {
            $item instanceof Project => $scope->seesProject($item),
            $item instanceof Section => $scope->seesSection($item),
            $item instanceof Task => $scope->seesTask($item),
        };
    }

    public function canMembershipSeeTask(Membership $membership, Task $task): bool
    {
        return $membership->isActive() && $this->scopeOf($membership)->seesTask($task);
    }

    public function scope(): WorkScope
    {
        $me = $this->me();

        return $me instanceof Membership ? $this->scopeOf($me) : WorkScope::nothing();
    }

    private function scopeOf(Membership $membership): WorkScope
    {
        return match ($membership->role()) {
            Role::Owner => WorkScope::everything(),
            Role::Member => WorkScope::shared(true, $this->shares->of($membership)),
            Role::Guest => WorkScope::shared(false, $this->shares->of($membership)),
        };
    }

    /**
     * The active membership of the signed-in person.
     */
    private function me(): ?Membership
    {
        $userId = $this->actor->userId();
        $membership = null === $userId ? null : $this->people->membershipOf($userId);

        return $membership instanceof Membership && $membership->isActive() ? $membership : null;
    }
}
