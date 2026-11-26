<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Application;

use Foresi\Meetings\Domain\Meeting;
use Foresi\Shared\Domain\Id;

/**
 * The meetings as the screens, the API and MCP read them: only those the person
 * sees (MeetingVisibility), anything else not found.
 */
final readonly class MeetingReads
{
    public function __construct(
        private MeetingVisibility $visibility,
        private MeetingsStore $store,
        private MeetingQueries $queries,
        private MeetingPeople $people,
        private MeetingTargets $targets,
    ) {
    }

    /**
     * @throws MeetingsRefused forbidden, not_found
     */
    public function one(string $id): Meeting
    {
        $this->visibility->denyUnlessStaff();
        $meeting = Id::isValid($id) ? $this->store->meeting($id) : null;
        if (!$meeting instanceof Meeting || !$this->visibility->sees($meeting)) {
            throw MeetingsRefused::meetingNotFound();
        }

        return $meeting;
    }

    /**
     * @throws MeetingsRefused forbidden, not_found
     */
    public function view(string $id): MeetingView
    {
        return $this->viewOf($this->one($id));
    }

    public function viewOf(Meeting $meeting): MeetingView
    {
        $attendees = $this->attendees([$meeting->id()])[$meeting->id()] ?? [];
        $links = [];
        foreach ($this->store->linksOf([$meeting->id()])[$meeting->id()] ?? [] as [$kind, $id]) {
            $link = $this->targets->view($kind, $id);
            if (null !== $link) {
                $links[] = $link;
            }
        }

        return new MeetingView($meeting, $attendees, $links, $this->visibility->mayTrash($meeting));
    }

    /**
     * @param list<string> $meetingIds
     *
     * @return array<string, list<array{id: string, name: string}>> the attendees by name, by meeting id
     */
    public function attendees(array $meetingIds): array
    {
        $result = [];
        foreach ($this->store->attendeesOf($meetingIds) as $meetingId => $membershipIds) {
            $people = array_map(fn (string $id): array => ['id' => $id, 'name' => $this->people->name($id) ?? ''], $membershipIds);
            usort($people, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
            $result[$meetingId] = $people;
        }

        return $result;
    }

    /**
     * @return list<Meeting>
     *
     * @throws MeetingsRefused forbidden
     */
    public function search(MeetingSearch $search): array
    {
        $this->visibility->denyUnlessStaff();

        return $this->queries->search($search);
    }
}
