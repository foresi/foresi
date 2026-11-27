<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Application;

use Foresi\Meetings\Domain\ActionItem;
use Foresi\Meetings\Domain\Attendee;
use Foresi\Meetings\Domain\Meeting;
use Foresi\Meetings\Domain\MeetingLink;
use Foresi\Meetings\Domain\MeetingSeries;
use Foresi\Meetings\Domain\SeriesMember;

/**
 * The series and meetings as they are stored, with no regard to who looks:
 * MeetingVisibility decides that. A write is flushed by the unit of work around it.
 */
interface MeetingsStore
{
    public function series(string $id): ?MeetingSeries;

    /**
     * The series of this name, whatever its case.
     */
    public function seriesNamed(string $name): ?MeetingSeries;

    /**
     * @return list<MeetingSeries> by name, the archived ones too
     */
    public function allSeries(): array;

    /**
     * @return list<SeriesMember>
     */
    public function members(MeetingSeries $series): array;

    /**
     * @return array<string, list<string>> the membership ids of the members of every series, by series id
     */
    public function membersBySeries(): array;

    /**
     * @return array<string, array{count: int, last: ?\DateTimeImmutable}> the meetings of each series not in the trash and the day of its last, by series id
     */
    public function seriesStats(): array;

    public function meeting(string $id): ?Meeting;

    /**
     * @return list<Attendee>
     */
    public function attendees(Meeting $meeting): array;

    /**
     * @param list<string> $meetingIds
     *
     * @return array<string, list<string>> the membership ids of the attendees by meeting id
     */
    public function attendeesOf(array $meetingIds): array;

    /**
     * @return list<MeetingLink>
     */
    public function links(Meeting $meeting): array;

    /**
     * @param list<string> $meetingIds
     *
     * @return array<string, list<array{string, string}>> the kind and id of each record linked, by meeting id
     */
    public function linksOf(array $meetingIds): array;

    public function actionItem(string $id): ?ActionItem;

    /**
     * @param list<string> $meetingIds
     *
     * @return list<ActionItem> of those meetings, in the order they were made
     */
    public function actionItemsOf(array $meetingIds): array;

    /**
     * @return list<ActionItem> the action items a task was made as, the oldest first
     */
    public function actionItemsOfTask(string $taskId): array;

    /**
     * @return list<Meeting> the meetings of the series not in the trash, the newest first
     */
    public function meetingsOf(MeetingSeries $series): array;

    /**
     * @return list<Meeting> those in the trash since before the moment, oldest first
     */
    public function trashedBefore(\DateTimeImmutable $moment): array;

    public function add(object $entity): void;

    public function remove(object $entity): void;
}
