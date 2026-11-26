<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Meetings\Application;

use Foresi\Audit\Application\AuditCorrelation;
use Foresi\Meetings\Application\Api\CreateMeetingInput;
use Foresi\Meetings\Application\Api\RestoreMeetingInput;
use Foresi\Meetings\Application\Api\SetMinutesInput;
use Foresi\Meetings\Application\Api\TrashMeetingInput;
use Foresi\Meetings\Application\Api\UpdateMeetingInput;
use Foresi\Meetings\Application\MeetingHistory;
use Foresi\Meetings\Application\MeetingHistoryEntry;
use Foresi\Meetings\Domain\Meeting;
use Foresi\Tests\Meetings\Support\MeetingsTestCase;
use Foresi\Work\Domain\Project;
use Foresi\Work\Domain\WorkflowTemplate;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;

/**
 * What a meeting's history tells, newest first: who created it, which fields changed,
 * who joined or left, how many records were linked or unlinked, and when it was trashed
 * and restored.
 */
final class MeetingHistoryTest extends MeetingsTestCase
{
    public function testTheHistoryTellsEachChangeWithItsPersonAndTime(): void
    {
        $meeting = $this->meetingWrites()->create(new CreateMeetingInput('2026-11-30', title: 'Budget review', attendeeIds: [$this->chief->id()]));

        Clock::set(new MockClock('2026-11-26 11:00:00', 'UTC'));
        $this->signIn($this->chief);
        $this->meetingWrites()->update(new UpdateMeetingInput($meeting->id(), title: 'Budget and hiring', heldOn: '2026-12-01', startsAt: '09:30'));

        Clock::set(new MockClock('2026-11-26 12:00:00', 'UTC'));
        $this->signIn($this->owner);
        $project = $this->project('Website');
        // One request: one step.
        self::getContainer()->get(AuditCorrelation::class)->within('edit', fn (): Meeting => $this->meetingWrites()->update(new UpdateMeetingInput($meeting->id(), attendeeIds: [$this->member->id()], links: [['kind' => 'project', 'id' => $project->id()]])));

        Clock::set(new MockClock('2026-11-26 13:00:00', 'UTC'));
        $this->meetingWrites()->setMinutes(new SetMinutesInput($meeting->id(), 'Approved the budget.', 1));
        self::getContainer()->get(AuditCorrelation::class)->within('edit', fn (): Meeting => $this->meetingWrites()->update(new UpdateMeetingInput($meeting->id(), links: [])));

        Clock::set(new MockClock('2026-11-26 14:00:00', 'UTC'));
        $this->meetingWrites()->trash(new TrashMeetingInput($meeting->id()));
        Clock::set(new MockClock('2026-11-26 15:00:00', 'UTC'));
        $this->meetingWrites()->restore(new RestoreMeetingInput($meeting->id()));

        $steps = array_map(
            static fn (MeetingHistoryEntry $step): array => [$step->at->format('H:i'), $step->userName, $step->kind, $step->fields, $step->added, $step->removed, $step->linked, $step->unlinked],
            $this->history()->of($meeting->id()),
        );

        self::assertSame([
            ['15:00', 'Olivia Owner', 'restored', [], [], [], 0, 0],
            ['14:00', 'Olivia Owner', 'trashed', [], [], [], 0, 0],
            ['13:00', 'Olivia Owner', 'changed', [], [], [], 0, 1],
            ['13:00', 'Olivia Owner', 'changed', ['meetings.meeting.field.minutes'], [], [], 0, 0],
            ['12:00', 'Olivia Owner', 'changed', [], ['Morgan Member'], ['Charlie Chief'], 1, 0],
            ['11:00', 'Charlie Chief', 'changed', ['meetings.meeting.field.title', 'meetings.meeting.field.held_on', 'meetings.meeting.field.starts_at'], [], [], 0, 0],
            ['10:00', 'Olivia Owner', 'changed', [], ['Charlie Chief'], [], 0, 0],
            ['10:00', 'Olivia Owner', 'created', [], [], [], 0, 0],
        ], $steps);
    }

    public function testAHistoryEntryStartsWithNothingAddedLinkedOrUnlinked(): void
    {
        $entry = new MeetingHistoryEntry(new \DateTimeImmutable('2026-11-26 10:00'), null, MeetingHistoryEntry::CHANGED);

        self::assertSame([[], [], [], 0, 0], [$entry->fields, $entry->added, $entry->removed, $entry->linked, $entry->unlinked]);
    }

    private function history(): MeetingHistory
    {
        return self::getContainer()->get(MeetingHistory::class);
    }

    private function project(string $name): Project
    {
        $project = new Project($name, null, WorkflowTemplate::Simple, $this->owner->user()->id(), new \DateTimeImmutable('2026-11-26 09:00'));
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }
}
