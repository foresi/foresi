<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Application;

use Foresi\Audit\Application\AuditActor;
use Foresi\Meetings\Domain\Attendee;
use Foresi\Meetings\Domain\Meeting;
use Foresi\Meetings\Domain\MeetingSeries;
use Foresi\Meetings\Domain\SeriesMember;
use Foresi\Meetings\Domain\SeriesVisibility;
use Foresi\Shared\Application\ActorRole;

/**
 * The one place that decides who sees a series or a meeting and who changes
 * them (spec decision M3), as WorkVisibility is in Work. In this order:
 *
 * 1. the owners of the firm see every series and meeting;
 * 2. a meeting of a series for the organisation is seen by everyone in the firm;
 * 3. a meeting of a series for its members by the members and its attendees;
 * 4. a one-off meeting by its attendees and the person who created it.
 *
 * A guest sees no meeting. Whoever sees a meeting writes its minutes and
 * changes it; the owners and the members of a series change the series; the
 * creator of a meeting and the owners put it in the trash and restore it. A
 * series for the organisation is seen by everyone in the firm, a series for
 * its members by its members, and every series by the owners.
 */
final class MeetingVisibility
{
    /** @var array<string, list<string>>|null */
    private ?array $members = null;

    public function __construct(
        private readonly ActorRole $role,
        private readonly AuditActor $actor,
        private readonly MeetingPeople $people,
        private readonly MeetingsStore $store,
    ) {
    }

    /**
     * @throws MeetingsRefused forbidden, for a guest or nobody signed in
     */
    public function denyUnlessStaff(): void
    {
        if (!$this->mayUse()) {
            throw MeetingsRefused::forbidden();
        }
    }

    public function mayUse(): bool
    {
        return null !== $this->actor->userId() && $this->role->isStaff();
    }

    public function isOwner(): bool
    {
        return $this->mayUse() && $this->role->isOwner();
    }

    public function seesSeries(MeetingSeries $series): bool
    {
        if (!$this->mayUse()) {
            return false;
        }

        return $this->role->isOwner() || SeriesVisibility::Organisation === $series->visibility() || $this->isMember($series);
    }

    public function mayChangeSeries(MeetingSeries $series): bool
    {
        return $this->mayUse() && ($this->role->isOwner() || $this->isMember($series));
    }

    public function sees(Meeting $meeting): bool
    {
        if (!$this->mayUse()) {
            return false;
        }
        if ($this->role->isOwner()) {
            return true;
        }
        $series = $meeting->series();
        if ($series instanceof MeetingSeries) {
            return SeriesVisibility::Organisation === $series->visibility() || $this->isMember($series) || $this->attends($meeting);
        }

        return $this->attends($meeting) || $meeting->createdBy() === $this->actor->userId();
    }

    public function mayTrash(Meeting $meeting): bool
    {
        return $this->mayUse() && ($this->role->isOwner() || $meeting->createdBy() === $this->actor->userId());
    }

    /**
     * The DQL condition that holds for the meetings the person sees, for a query
     * where $meeting is the meeting and $series its series joined on the left.
     *
     * @return array{string, array<string, string>} the condition and its parameters
     */
    public function condition(string $meeting, string $series, string $attendee, string $member): array
    {
        if (!$this->mayUse()) {
            return ['1 = 0', []];
        }
        if ($this->role->isOwner()) {
            return ['1 = 1', []];
        }
        $me = $this->people->me() ?? '';
        $user = $this->actor->userId() ?? '';

        $terms = [
            "%2\$s.visibility = '%5\$s'",
            'EXISTS (SELECT 1 FROM %6$s %4$s WHERE %4$s.series = %2$s AND %4$s.membershipId = :visibility_me)',
            'EXISTS (SELECT 1 FROM %7$s %3$s WHERE %3$s.meeting = %1$s AND %3$s.membershipId = :visibility_me)',
            '(%2$s.id IS NULL AND %1$s.createdBy = :visibility_user)',
        ];

        return [
            \sprintf(
                '('.implode(' OR ', $terms).')',
                $meeting,
                $series,
                $attendee,
                $member,
                SeriesVisibility::Organisation->value,
                SeriesMember::class,
                Attendee::class,
            ),
            ['visibility_me' => $me, 'visibility_user' => $user],
        ];
    }

    private function isMember(MeetingSeries $series): bool
    {
        $me = $this->people->me();
        $this->members ??= $this->store->membersBySeries();

        return null !== $me && \in_array($me, $this->members[$series->id()] ?? [], true);
    }

    private function attends(Meeting $meeting): bool
    {
        $me = $this->people->me();

        return null !== $me && \in_array($me, $this->store->attendeesOf([$meeting->id()])[$meeting->id()] ?? [], true);
    }

    /**
     * Forgets what it read of the members, after a write changed them.
     */
    public function forget(): void
    {
        $this->members = null;
    }
}
