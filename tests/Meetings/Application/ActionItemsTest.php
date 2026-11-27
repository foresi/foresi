<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Meetings\Application;

use Foresi\Events\Infrastructure\Dbal\EventLogTable;
use Foresi\Meetings\Application\ActionItemReads;
use Foresi\Meetings\Application\ActionItemWrites;
use Foresi\Meetings\Application\Api\CreateActionItemInput;
use Foresi\Meetings\Application\Api\CreateMeetingInput;
use Foresi\Meetings\Application\Api\CreateSeriesInput;
use Foresi\Meetings\Application\Api\SetMinutesInput;
use Foresi\Meetings\Application\CarriedOver;
use Foresi\Meetings\Application\MeetingsClock;
use Foresi\Meetings\Application\MeetingsRefused;
use Foresi\Meetings\Application\MeetingsStore;
use Foresi\Meetings\Domain\ActionItem;
use Foresi\Meetings\Domain\Meeting;
use Foresi\Meetings\Domain\MeetingSeries;
use Foresi\Meetings\Infrastructure\Screen\TaskPills;
use Foresi\Meetings\Infrastructure\Slot\MeetingTaskFacts;
use Foresi\Shared\Application\TaskReferenceSyntax;
use Foresi\Shared\Domain\Id;
use Foresi\Tests\Meetings\Support\MeetingsTestCase;
use Foresi\Work\Application\ManageProjects;
use Foresi\Work\Application\ManageTasks;
use Foresi\Work\Contract\TaskDirectory;
use Foresi\Work\Contract\TaskSlots;
use Foresi\Work\Contract\TaskSummary;
use Foresi\Work\Domain\Project;
use Foresi\Work\Domain\ProjectStatus;
use Foresi\Work\Domain\Task;
use Symfony\Component\Clock\Clock;

/**
 * Tasks from the minutes (plan 38.3): made with their link in one unit of
 * work, once per key; kept when the token leaves the text; shown live with
 * their pill as the person sees them; carried over by the horizon rule M2a.
 */
final class ActionItemsTest extends MeetingsTestCase
{
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = self::getContainer()->get(ManageProjects::class)->create('Website', null);
    }

    public function testATaskFromTheEditorIsMadeOnceForItsKeyWithItsEvent(): void
    {
        $meeting = $this->meeting('2026-11-26');
        $key = Id::generate();

        $first = $this->writes()->fromEditor($meeting->id(), strtoupper($key), ' Send the offer ', $this->project->id(), $this->member->id(), new \DateTimeImmutable('2026-12-01'), false);
        $again = $this->writes()->fromEditor($meeting->id(), $key, 'Send the offer', $this->project->id(), null, null, false);

        self::assertSame($first->taskId(), $again->taskId());
        self::assertSame($key, $first->id());
        self::assertSame(1, $this->em->getRepository(Task::class)->count([]));
        $task = $this->em->find(Task::class, $first->taskId());
        self::assertInstanceOf(Task::class, $task);
        self::assertSame('Send the offer', $task->title());
        self::assertSame([['meeting_id' => $meeting->id(), 'series_id' => $meeting->series()?->id(), 'task_id' => $first->taskId()]], $this->events('meetings.action_item.created'));
        self::assertCount(1, $this->events('work.task.created'));

        $other = $this->meeting('2026-11-27');
        $this->refused('key', fn (): ActionItem => $this->writes()->fromEditor($other->id(), $key, 'Send the offer', $this->project->id(), null, null, false));
        $this->refused('key', fn (): ActionItem => $this->writes()->fromEditor($meeting->id(), 'not-a-key', 'Send the offer', $this->project->id(), null, null, false));
        $this->signIn($this->chief);
        $this->refused('key', fn (): ActionItem => $this->writes()->fromEditor($meeting->id(), $key, 'Send the offer', $this->project->id(), null, null, false));
        self::assertSame(1, $this->em->getRepository(Task::class)->count([]));
    }

    public function testATrashedMeetingTakesNoTaskAndNothingIsLeftOfAFailedOne(): void
    {
        $meeting = $this->meeting('2026-11-26');
        try {
            $this->writes()->fromEditor($meeting->id(), Id::generate(), '', $this->project->id(), null, null, false);
            self::fail('A task without a title was made.');
        } catch (\Foresi\Shared\Contract\Refused $refused) {
            self::assertSame('name', $refused->reason());
        }
        self::assertSame([], $this->store()->actionItemsOf([$meeting->id()]));

        $this->meetingWrites()->trash(new \Foresi\Meetings\Application\Api\TrashMeetingInput($meeting->id()));
        $this->refused('trashed', fn (): ActionItem => $this->writes()->fromEditor($meeting->id(), Id::generate(), 'Late', $this->project->id(), null, null, false));
    }

    public function testRemovingTheTokenKeepsTheTaskAndItsLink(): void
    {
        $meeting = $this->meeting('2026-11-26');
        $item = $this->writes()->fromEditor($meeting->id(), Id::generate(), 'Send the offer', $this->project->id(), null, null, false);
        $this->meetingWrites()->setMinutes(new SetMinutesInput($meeting->id(), '- '.TaskReferenceSyntax::token('Send the offer', $item->taskId()), 1));
        self::assertSame([$item->taskId()], array_keys($this->reads()->page($meeting, $meeting->minutes())->summaries));

        $this->meetingWrites()->setMinutes(new SetMinutesInput($meeting->id(), '- Nothing left here', 2));

        $page = $this->reads()->page($meeting, $meeting->minutes());
        self::assertSame([$item->taskId()], array_map(static fn (TaskSummary $task): string => $task->id, $page->actionItems));
        self::assertInstanceOf(Task::class, $this->em->find(Task::class, $item->taskId()));
        $facts = [...self::getContainer()->get(MeetingTaskFacts::class)->items(TaskSlots::HEADER_FACTS, ['taskId' => $item->taskId()])];
        self::assertCount(1, $facts);
        self::assertSame($meeting->title(), $facts[0]->data['meeting']);
        self::assertSame('/meetings/'.$meeting->id(), $facts[0]->data['href']);
        self::assertSame([], [...self::getContainer()->get(MeetingTaskFacts::class)->items('work.task.other', ['taskId' => $item->taskId()])]);
    }

    public function testThePillsShowTheTrashAndHideATaskThePersonDoesNotSee(): void
    {
        $meeting = $this->meeting('2026-11-26');
        $open = $this->task($meeting, 'Write the brief', due: '2026-11-20', responsible: $this->member->id());
        $private = $this->task($meeting, 'Agree the price', private: true);
        $trashed = $this->task($meeting, 'Old idea');
        self::getContainer()->get(ManageTasks::class)->delete($trashed);
        $purged = Id::generate();
        $minutes = implode("\n", array_map(static fn (array $pair): string => '- '.TaskReferenceSyntax::token($pair[0], $pair[1]), [['Brief', $open], ['Price', $private], ['Idea', $trashed], ['Gone', $purged]]));

        $this->signIn($this->member);
        $page = $this->reads()->page($meeting, $minutes);
        $html = $this->pills()->minutes($minutes, $page->summaries);

        self::assertStringContainsString('data-task="'.$open.'">Brief</span>', $html);
        self::assertStringContainsString('data-task-pill="overdue"', $html);
        self::assertStringContainsString('href="/tasks/'.$open.'"', $html);
        self::assertStringContainsString('Morgan Member', $html);
        self::assertStringContainsString('data-task="'.$trashed.'">Idea</span>', $html);
        self::assertStringContainsString('data-task-pill="trashed"', $html);
        self::assertStringNotContainsString('href="/tasks/'.$trashed.'"', $html);
        self::assertStringContainsString('<li>Price</li>', $html);
        self::assertStringContainsString('<li>Gone</li>', $html);
        self::assertStringNotContainsString($private, $html);
        self::assertSame([$open, $trashed], array_map(static fn (TaskSummary $task): string => $task->id, $page->actionItems));
        self::assertTrue($this->reads()->overdue($page->summaries[$open]));
    }

    public function testTheCarryOverFollowsTheHorizonRule(): void
    {
        $older = $this->meeting('2026-11-05');
        $old = $this->meeting('2026-11-12');
        $previous = $this->meeting('2026-11-19');
        $current = $this->meeting('2026-11-26');
        $later = $this->meeting('2026-12-03');
        $oldUndated = $this->task($old, 'Undated, older');
        $daySeven = $this->task($old, 'Due on day 7', due: '2026-12-03');
        $this->task($old, 'Due on day 8', due: '2026-12-04');
        $overdue = $this->task($older, 'Overdue, older', due: '2026-11-20');
        $previousUndated = $this->task($previous, 'Undated, previous');
        $previousFar = $this->task($previous, 'Far, previous', due: '2027-03-01');
        $done = $this->task($previous, 'Done, previous');
        $this->complete($done);
        $trashed = $this->task($previous, 'Trashed, previous');
        self::getContainer()->get(ManageTasks::class)->delete($trashed);
        $own = $this->task($current, 'Own task');
        $this->task($later, 'Later task');

        $page = $this->reads()->page($current, '');

        self::assertSame([
            [$previous->id(), [$previousUndated, $previousFar]],
            [$old->id(), [$daySeven]],
            [$older->id(), [$overdue]],
        ], array_map(static fn (CarriedOver $group): array => [$group->meeting->id(), array_map(static fn (TaskSummary $task): string => $task->id, $group->tasks)], $page->carryOver));
        self::assertNotContains($oldUndated, array_merge(...array_map(static fn (CarriedOver $group): array => array_map(static fn (TaskSummary $task): string => $task->id, $group->tasks), $page->carryOver)));
        self::assertSame([$own], array_map(static fn (TaskSummary $task): string => $task->id, $page->actionItems));
        // Open: three of the old ones, the overdue one, two of the previous, the own one and the later one.
        self::assertSame(8, $page->seriesOpen);
        self::assertSame([$later->id(), $current->id(), $previous->id(), $old->id(), $older->id()], array_map(static fn (CarriedOver $group): string => $group->meeting->id(), $this->reads()->openOfSeries($this->series($current))));

        $oneOff = $this->meetingWrites()->create(new CreateMeetingInput('2026-11-26', title: 'Ad hoc', attendeeIds: [$this->owner->id()]));
        self::assertSame([], $this->reads()->page($oneOff, '')->carryOver);
    }

    public function testAnAgentPutsTheTokenInPlaceOfTheQuoteOrAppendsIt(): void
    {
        $meeting = $this->meeting('2026-11-26');
        $this->meetingWrites()->setMinutes(new SetMinutesInput($meeting->id(), "## Next steps\n\n- Send the offer to Acme\n- Call the bank", 1));

        [$quoted, $after] = $this->writes()->create(new CreateActionItemInput($meeting->id(), $this->project->id(), 'Send the offer', quote: 'Send the offer'));
        self::assertSame("## Next steps\n\n- ".TaskReferenceSyntax::token('Send the offer', $quoted->taskId())." to Acme\n- Call the bank", $after->minutes());
        self::assertSame(3, $after->version());

        [$appended, $after] = $this->writes()->create(new CreateActionItemInput($meeting->id(), $this->project->id(), 'Book the venue', dueOn: '2026-12-02'));
        self::assertStringEndsWith("- Call the bank\n- ".TaskReferenceSyntax::token('Book the venue', $appended->taskId())."\n", $after->minutes());

        $this->refused('quote_not_found', fn (): array => $this->writes()->create(new CreateActionItemInput($meeting->id(), $this->project->id(), 'Again', quote: 'Send the offer')));
        $this->refused('due_on', fn (): array => $this->writes()->create(new CreateActionItemInput($meeting->id(), $this->project->id(), 'Again', dueOn: '2026-02-30')));
        self::assertSame(2, $this->em->getRepository(Task::class)->count([]));
    }

    public function testAQuoteLeftEmptyAppendsTheTokenAndATaskIsPublicUnlessSaidOtherwise(): void
    {
        $meeting = $this->meeting('2026-11-26');
        $this->meetingWrites()->setMinutes(new SetMinutesInput($meeting->id(), '- Call the bank', 1));

        [$item, $after] = $this->writes()->create(new CreateActionItemInput($meeting->id(), $this->project->id(), '  Book the venue  ', quote: ''));

        self::assertSame("- Call the bank\n- ".TaskReferenceSyntax::token('Book the venue', $item->taskId())."\n", $after->minutes());
        $task = $this->em->find(Task::class, $item->taskId());
        self::assertInstanceOf(Task::class, $task);
        self::assertSame('Book the venue', $task->title());
        self::assertFalse($task->isPrivate());
        self::assertFalse(new CreateActionItemInput($meeting->id(), $this->project->id(), 'Book the venue')->private);
        self::assertSame([['meeting_id' => $meeting->id(), 'series_id' => $meeting->series()?->id(), 'version' => 3]], array_slice($this->events('meetings.meeting.minutes_changed'), -1));
    }

    public function testTheDueDayOfATaskFromAnAgentIsAWholeValidDay(): void
    {
        $meeting = $this->meeting('2026-11-26');

        foreach (['2026-12-01x', 'x2026-12-01', "2026-12-01\n", '2026-1-5', '2026-13-01', 'tomorrow'] as $dueOn) {
            $this->refused('due_on', fn (): array => $this->writes()->create(new CreateActionItemInput($meeting->id(), $this->project->id(), 'Book the venue', dueOn: $dueOn)));
        }
        self::assertSame(0, $this->em->getRepository(Task::class)->count([]));

        [$item] = $this->writes()->create(new CreateActionItemInput($meeting->id(), $this->project->id(), 'Book the venue', dueOn: '2026-12-01'));
        self::assertSame('2026-12-01', $this->reads()->page($meeting, '')->summaries[$item->taskId()]->dueOn?->format('Y-m-d'));
    }

    public function testTheMinutesWithTheNewTokenMayHaveExactlyTheMostCharactersCountedInCharacters(): void
    {
        $meeting = $this->meeting('2026-11-26');
        // The appended token adds 53 characters to the list: "\n", "- ", the token of 49 and "\n".
        $room = Meeting::MAX_MINUTES - 53;
        $this->meetingWrites()->setMinutes(new SetMinutesInput($meeting->id(), '- '.str_repeat('é', $room - 2), 1));

        [, $after] = $this->writes()->create(new CreateActionItemInput($meeting->id(), $this->project->id(), 'Book'));

        self::assertSame(Meeting::MAX_MINUTES, mb_strlen($after->minutes()));
        self::assertGreaterThan(Meeting::MAX_MINUTES, \strlen($after->minutes()));

        $other = $this->meeting('2026-12-03');
        $this->meetingWrites()->setMinutes(new SetMinutesInput($other->id(), '- '.str_repeat('é', $room - 1), 1));
        $this->refused('minutes_too_long', fn (): array => $this->writes()->create(new CreateActionItemInput($other->id(), $this->project->id(), 'Book')));
        self::assertSame(1, $this->em->getRepository(Task::class)->count([]));
    }

    public function testOnlyStaffMakeATaskInAMeetingTheySee(): void
    {
        $meeting = $this->meeting('2026-11-26');
        $input = new CreateActionItemInput($meeting->id(), $this->project->id(), 'Book the venue');

        $this->signIn($this->guest);
        $this->refused('forbidden', fn (): array => $this->writes()->create($input));

        $this->signIn($this->owner);
        $this->refused('not_found', fn (): array => $this->writes()->create(new CreateActionItemInput('not-an-id', $this->project->id(), 'Book the venue')));
        $this->refused('not_found', fn (): array => $this->writes()->create(new CreateActionItemInput(Id::generate(), $this->project->id(), 'Book the venue')));

        $board = $this->seriesWrites()->create(new CreateSeriesInput('Board', 'members', memberIds: [$this->owner->id()]));
        $hidden = $this->meetingWrites()->create(new CreateMeetingInput('2026-11-30', $board->id(), attendeeIds: [$this->owner->id()]));
        $this->signIn($this->chief);
        $this->refused('not_found', fn (): array => $this->writes()->create(new CreateActionItemInput($hidden->id(), $this->project->id(), 'Book the venue')));
        self::assertSame(0, $this->em->getRepository(Task::class)->count([]));
    }

    public function testOverdueIsAnOpenTaskPastItsDueDayNotOnIt(): void
    {
        $meeting = $this->meeting('2026-11-26');
        $yesterday = $this->task($meeting, 'Yesterday', due: '2026-11-25');
        $today = $this->task($meeting, 'Today', due: '2026-11-26');
        $undated = $this->task($meeting, 'Undated');
        $finished = $this->task($meeting, 'Finished', due: '2026-11-25');
        $this->complete($finished);

        $summaries = $this->reads()->page($meeting, '')->summaries;

        self::assertSame(
            [true, false, false, false],
            array_map(fn (string $id): bool => $this->reads()->overdue($summaries[$id]), [$yesterday, $today, $undated, $finished]),
        );
    }

    public function testWorkIsAskedOnceForEachTaskInAListAndNeverForAMeetingThePersonDoesNotSee(): void
    {
        $board = $this->seriesWrites()->create(new CreateSeriesInput('Board', 'members', memberIds: [$this->owner->id()]));
        $attendees = [$this->owner->id(), $this->chief->id()];
        $first = $this->meetingWrites()->create(new CreateMeetingInput('2026-11-19', $board->id(), attendeeIds: $attendees));
        $current = $this->meetingWrites()->create(new CreateMeetingInput('2026-11-26', $board->id(), attendeeIds: $attendees));
        $hidden = $this->meetingWrites()->create(new CreateMeetingInput('2026-12-03', $board->id(), attendeeIds: [$this->owner->id()]));
        $oneOff = $this->meetingWrites()->create(new CreateMeetingInput('2026-11-26', title: 'Ad hoc', attendeeIds: [$this->owner->id()]));
        $shared = $this->task($first, 'Shared');
        Clock::get()->sleep(60);
        // The same task kept on a second meeting: it must be asked for once.
        $this->store()->add(new ActionItem(Id::generate(), $current, $shared, $this->owner->user()->id(), \DateTimeImmutable::createFromInterface(Clock::get()->now())));
        $this->em->flush();
        Clock::get()->sleep(60);
        $second = $this->task($first, 'Second');
        Clock::get()->sleep(60);
        $finished = $this->task($first, 'Finished');
        $this->complete($finished);
        $this->task($hidden, 'Hidden');
        $elsewhere = $this->task($oneOff, 'Elsewhere');
        $minutes = '- '.TaskReferenceSyntax::token('Shared', $shared).' - '.TaskReferenceSyntax::token('Elsewhere', $elsewhere);

        $this->signIn($this->chief);
        $stores = $this->recorder();
        $directories = $this->recorder();
        $reads = new ActionItemReads($this->spiedStore($stores), $this->visibility(), $this->spiedDirectory($directories), self::getContainer()->get(MeetingsClock::class));

        $page = $reads->page($current, $minutes);

        self::assertSame([[$current->id(), $first->id()]], $stores->getArrayCopy());
        self::assertSame([[$shared, $elsewhere, $second, $finished]], $directories->getArrayCopy());
        self::assertSame(2, $page->seriesOpen);

        $stores = $this->recorder();
        $directories = $this->recorder();
        $reads = new ActionItemReads($this->spiedStore($stores), $this->visibility(), $this->spiedDirectory($directories), self::getContainer()->get(MeetingsClock::class));
        $groups = $reads->openOfSeries($this->series($current));

        self::assertSame([[$current->id(), $first->id()]], $stores->getArrayCopy());
        self::assertSame([[$shared, $second, $finished]], $directories->getArrayCopy());
        self::assertSame(
            [[$current->id(), [$shared]], [$first->id(), [$shared, $second]]],
            array_map(static fn (CarriedOver $group): array => [$group->meeting->id(), array_map(static fn (TaskSummary $task): string => $task->id, $group->tasks)], $groups),
        );
    }

    /**
     * @return \ArrayObject<int, array<mixed>>
     */
    private function recorder(): \ArrayObject
    {
        return new \ArrayObject();
    }

    /**
     * @param \ArrayObject<int, array<mixed>> $asked the meeting ids each read of action items is made with
     */
    private function spiedStore(\ArrayObject $asked): MeetingsStore
    {
        $real = $this->store();
        $store = self::createStub(MeetingsStore::class);
        $store->method('meetingsOf')->willReturnCallback(static fn (MeetingSeries $series): array => $real->meetingsOf($series));
        $store->method('actionItemsOf')->willReturnCallback(static function (array $ids) use ($real, $asked): array {
            $asked[] = $ids;

            return $real->actionItemsOf(array_values(array_filter($ids, \is_string(...))));
        });

        return $store;
    }

    /**
     * @param \ArrayObject<int, array<mixed>> $asked the task ids each read of Work is made with
     */
    private function spiedDirectory(\ArrayObject $asked): TaskDirectory
    {
        $real = self::getContainer()->get(TaskDirectory::class);
        $directory = self::createStub(TaskDirectory::class);
        $directory->method('summaries')->willReturnCallback(static function (array $ids) use ($real, $asked): array {
            $asked[] = $ids;

            return $real->summaries(array_values(array_filter($ids, \is_string(...))));
        });

        return $directory;
    }

    private function meeting(string $day): Meeting
    {
        $series = $this->store()->seriesNamed('Production weekly') ?? $this->seriesWrites()->create(new CreateSeriesInput('Production weekly', 'organisation', memberIds: [$this->owner->id()]));

        return $this->meetingWrites()->create(new CreateMeetingInput($day, $series->id()));
    }

    private function series(Meeting $meeting): MeetingSeries
    {
        $series = $meeting->series();
        self::assertInstanceOf(MeetingSeries::class, $series);

        return $series;
    }

    private function task(Meeting $meeting, string $title, ?string $due = null, ?string $responsible = null, bool $private = false): string
    {
        return $this->writes()->fromEditor($meeting->id(), Id::generate(), $title, $this->project->id(), $responsible, null === $due ? null : new \DateTimeImmutable($due), $private)->taskId();
    }

    private function complete(string $taskId): void
    {
        $done = $this->em->getRepository(ProjectStatus::class)->findOneBy(['project' => $this->project, 'type' => 'done']);
        self::assertInstanceOf(ProjectStatus::class, $done);
        self::getContainer()->get(ManageTasks::class)->changeStatus($taskId, $done->id());
    }

    private function refused(string $reason, \Closure $write): void
    {
        try {
            $write();
            self::fail('Not refused: '.$reason);
        } catch (MeetingsRefused $refused) {
            self::assertSame($reason, $refused->reason());
        }
    }

    private function writes(): ActionItemWrites
    {
        return self::getContainer()->get(ActionItemWrites::class);
    }

    private function reads(): ActionItemReads
    {
        return self::getContainer()->get(ActionItemReads::class);
    }

    private function store(): MeetingsStore
    {
        return self::getContainer()->get(MeetingsStore::class);
    }

    /**
     * @return list<array<mixed>>
     */
    private function events(string $name): array
    {
        $rows = $this->em->getConnection()->fetchFirstColumn('SELECT '.EventLogTable::DATA.' FROM '.EventLogTable::LOG.' WHERE '.EventLogTable::NAME.' = ? ORDER BY '.EventLogTable::ID, [$name]);

        $events = [];
        foreach ($rows as $data) {
            self::assertIsString($data);
            $decoded = json_decode($data, true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            $events[] = $decoded;
        }

        return $events;
    }

    private function pills(): TaskPills
    {
        return self::getContainer()->get(TaskPills::class);
    }
}
