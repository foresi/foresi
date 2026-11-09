<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

use App\Audit\Application\AuditTrail;
use App\Audit\Domain\AuditAction;
use App\Audit\Domain\AuditEntry;
use App\Identity\Application\People;
use App\Identity\Application\Person;
use App\Identity\Application\UserRepository;
use App\Identity\Domain\Role;
use App\Work\Domain\Project;
use App\Work\Domain\ProjectVisibility;
use App\Work\Domain\Section;
use App\Work\Domain\Task;
use App\Work\Domain\WorkShare;

/**
 * What the sharing dialog of a project, a section or a task shows, to whom
 * may share it: the people it is shared with, the others it can be shared
 * with, and for a project or a section the steps of who sees it from the log
 * of the project. A task tells those in its own history.
 */
final readonly class SharingQuery
{
    public function __construct(
        private VisibleWork $work,
        private WorkShares $shares,
        private People $people,
        private AuditTrail $trail,
        private UserRepository $users,
    ) {
    }

    /**
     * @throws WorkRefused when the item is unknown or not visible ("unknown"), or the person may not share it
     */
    public function of(ShareKind $kind, string $id): SharingView
    {
        $item = match ($kind) {
            ShareKind::Project => $this->work->project($id),
            ShareKind::Section => $this->work->section($id),
            ShareKind::Task => $this->work->task($id),
        };
        $this->work->assertSharer($item);
        $project = $item instanceof Project ? $item : $item->project();

        $persons = [];
        foreach ($this->people->all() as $person) {
            $persons[$person->membershipId] = $person;
        }
        $people = [];
        foreach ($this->shares->on($item) as $share) {
            $membership = $share->membership();
            $person = $persons[$membership->id()] ?? null;
            $people[] = new SharedPerson($membership->id(), $membership->user()->id(), $person->name ?? $membership->user()->displayName(), $membership->role());
        }
        usort($people, static fn (SharedPerson $a, SharedPerson $b): int => [mb_strtolower($a->name), $a->membershipId] <=> [mb_strtolower($b->name), $b->membershipId]);
        $shared = array_map(static fn (SharedPerson $person): string => $person->membershipId, $people);
        $candidates = array_values(array_filter(
            $persons,
            static fn (Person $person): bool => $person->active && Role::Owner !== $person->role && !\in_array($person->membershipId, $shared, true),
        ));
        usort($candidates, static fn (Person $a, Person $b): int => [mb_strtolower($a->name), $a->membershipId] <=> [mb_strtolower($b->name), $b->membershipId]);

        return new SharingView(
            $kind,
            $item->id(),
            match (true) {
                $item instanceof Task => $item->title(),
                default => $item->name(),
            },
            $project->id(),
            $project->name(),
            match (true) {
                $item instanceof Project => ProjectVisibility::Chosen === $item->visibility(),
                default => $item->isPrivate(),
            },
            $people,
            array_map(static fn (Person $person): Choice => new Choice($person->membershipId, $person->name), $candidates),
            $item instanceof Task ? [] : $this->history($item, array_map(static fn (Person $person): string => $person->name, $persons)),
        );
    }

    /**
     * @param array<string, string> $names of the people by membership id
     *
     * @return list<SharingHistoryEntry> oldest first
     */
    private function history(Project|Section $item, array $names): array
    {
        $project = $item instanceof Project ? $item : $item->project();
        $entries = $this->trail->rootHistory(Project::class, $project->id());
        $users = $this->users->namesOf(array_values(array_unique(array_filter(array_map(static fn (AuditEntry $entry): ?string => $entry->userId(), $entries), \is_string(...)))));
        $own = $item instanceof Project ? 'project' : 'section';
        $steps = [];
        foreach (AuditSteps::of($entries) as $group) {
            $closed = null;
            $shares = [[], []];
            foreach ($group as $entry) {
                $changes = $entry->changes();
                $removed = AuditAction::Deleted === $entry->action();
                if (WorkShare::class === $entry->entityClass() && ($changes[$own][$removed ? 0 : 1] ?? null) === $item->id()) {
                    $membership = $changes['membership'][$removed ? 0 : 1] ?? null;
                    if (\is_string($membership)) {
                        $shares[$removed ? 1 : 0][] = $names[$membership] ?? '';
                    }
                } elseif ($entry->entityClass() === ($item instanceof Project ? Project::class : Section::class) && $entry->entityId() === $item->id() && AuditAction::Updated === $entry->action()) {
                    if ($item instanceof Project && \array_key_exists('visibility', $changes)) {
                        $closed = ProjectVisibility::Chosen->value === ($changes['visibility'][1] ?? null);
                    } elseif ($item instanceof Section && \array_key_exists('private', $changes)) {
                        $closed = TaskHistory::flag($changes['private'][1] ?? null);
                    }
                }
            }
            if (null !== $closed || [[], []] !== $shares) {
                $userId = $group[0]->userId();
                $steps[] = new SharingHistoryEntry($group[0]->occurredAt(), null === $userId ? null : ($users[$userId] ?? null), $closed, $shares[0], $shares[1]);
            }
        }

        return $steps;
    }
}
