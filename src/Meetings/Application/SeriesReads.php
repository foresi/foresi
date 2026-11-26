<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Application;

use Foresi\Meetings\Domain\MeetingSeries;
use Foresi\Meetings\Domain\SeriesMember;
use Foresi\Shared\Domain\Id;

/**
 * The series as the screens, the API and MCP read them: only those the person
 * sees (MeetingVisibility).
 */
final readonly class SeriesReads
{
    public function __construct(
        private MeetingVisibility $visibility,
        private MeetingsStore $store,
        private MeetingPeople $people,
    ) {
    }

    /**
     * @return list<MeetingSeries> by name
     *
     * @throws MeetingsRefused forbidden
     */
    public function all(bool $archived = false): array
    {
        $this->visibility->denyUnlessStaff();

        return array_values(array_filter($this->store->allSeries(), fn (MeetingSeries $series): bool => ($archived || !$series->isArchived()) && $this->visibility->seesSeries($series)));
    }

    /**
     * @return list<SeriesView> by name
     *
     * @throws MeetingsRefused forbidden
     */
    public function views(bool $archived = false): array
    {
        $stats = $this->store->seriesStats();

        return array_map(fn (MeetingSeries $series): SeriesView => $this->viewOf($series, $stats), $this->all($archived));
    }

    /**
     * @throws MeetingsRefused forbidden, not_found
     */
    public function one(string $id): MeetingSeries
    {
        $this->visibility->denyUnlessStaff();
        $series = Id::isValid($id) ? $this->store->series($id) : null;
        if (!$series instanceof MeetingSeries || !$this->visibility->seesSeries($series)) {
            throw MeetingsRefused::seriesNotFound();
        }

        return $series;
    }

    /**
     * @throws MeetingsRefused forbidden, not_found
     */
    public function view(string $id): SeriesView
    {
        return $this->viewOf($this->one($id), $this->store->seriesStats());
    }

    /**
     * @param array<string, array{count: int, last: ?\DateTimeImmutable}> $stats
     */
    private function viewOf(MeetingSeries $series, array $stats): SeriesView
    {
        $members = array_map(fn (SeriesMember $member): array => ['id' => $member->membershipId(), 'name' => $this->people->name($member->membershipId()) ?? '', 'default_attendee' => $member->isDefaultAttendee()], $this->store->members($series));
        usort($members, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

        return new SeriesView($series, $members, $stats[$series->id()]['count'] ?? 0, $stats[$series->id()]['last'] ?? null, $this->visibility->mayChangeSeries($series));
    }
}
