<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Application;

use Foresi\Meetings\Domain\ActionItem;
use Foresi\Meetings\Domain\Meeting;
use Foresi\Meetings\Domain\MeetingSeries;
use Foresi\Shared\Application\TaskReferenceSyntax;
use Foresi\Work\Contract\TaskDirectory;
use Foresi\Work\Contract\TaskSummary;

/**
 * The tasks of the meetings, read live through Work's TaskDirectory as the
 * person sees them: nothing is copied, so a task done anywhere leaves every
 * list at the next render (spec, Carry-over as a view, decision M2).
 *
 * The carry-over of a meeting of a series is the open tasks (not done, not in
 * the trash) of the meetings of the series held before it, by the horizon rule
 * M2a: every open task of the previous meeting; of an older meeting only those
 * overdue or due within 7 days of this meeting's day. A task without a due day
 * so shows at one meeting and then ages out (Lattice; EOS/Ninety's 7-day
 * to-dos). A one-off meeting carries nothing over.
 */
final readonly class ActionItemReads
{
    public const int HORIZON_DAYS = 7;

    public function __construct(
        private MeetingsStore $store,
        private MeetingVisibility $visibility,
        private TaskDirectory $directory,
        private MeetingsClock $clock,
    ) {
    }

    /**
     * The tasks of the meeting's page, with those its minutes refer to, in one read of Work.
     */
    public function page(Meeting $meeting, string $minutes): MeetingTasks
    {
        $series = $meeting->series();
        $meetings = $series instanceof MeetingSeries ? array_filter($this->store->meetingsOf($series), $this->visibility->sees(...)) : [];
        $items = $this->store->actionItemsOf(array_values(array_unique([$meeting->id(), ...array_map(static fn (Meeting $other): string => $other->id(), $meetings)])));
        $summaries = $this->directory->summaries(array_values(array_unique([...TaskReferenceSyntax::ids($minutes), ...array_map(static fn (ActionItem $item): string => $item->taskId(), $items)])));
        $byMeeting = [];
        foreach ($items as $item) {
            $byMeeting[$item->meeting()->id()][] = $item;
        }
        $tasks = fn (Meeting $of): array => array_values(array_filter(array_map(static fn (ActionItem $item): ?TaskSummary => $summaries[$item->taskId()] ?? null, $byMeeting[$of->id()] ?? [])));
        $carryOver = [];
        $previous = true;
        $seriesOpen = [];
        foreach ($meetings as $other) {
            foreach ($tasks($other) as $task) {
                if ($this->open($task)) {
                    $seriesOpen[$task->id] = $task->id;
                }
            }
            if ($other->heldOn() >= $meeting->heldOn()) {
                continue;
            }
            $open = array_values(array_filter($tasks($other), fn (TaskSummary $task): bool => $this->open($task) && ($previous || $this->nearOrOverdue($task, $meeting))));
            $previous = false;
            if ([] !== $open) {
                $carryOver[] = new CarriedOver($other, $open);
            }
        }

        return new MeetingTasks($summaries, $tasks($meeting), $carryOver, \count($seriesOpen));
    }

    /**
     * The tasks the minutes refer to that the person sees, by id, in one read of Work.
     *
     * @return array<string, TaskSummary>
     */
    public function referred(string $minutes): array
    {
        $ids = TaskReferenceSyntax::ids($minutes);

        return [] === $ids ? [] : $this->directory->summaries($ids);
    }

    /**
     * Every open task of the meetings of a series the person sees, by meeting, newest first.
     *
     * @return list<CarriedOver>
     */
    public function openOfSeries(MeetingSeries $series): array
    {
        $meetings = array_values(array_filter($this->store->meetingsOf($series), $this->visibility->sees(...)));
        $items = $this->store->actionItemsOf(array_map(static fn (Meeting $meeting): string => $meeting->id(), $meetings));
        $summaries = $this->directory->summaries(array_values(array_unique(array_map(static fn (ActionItem $item): string => $item->taskId(), $items))));
        $groups = [];
        foreach ($meetings as $meeting) {
            $open = [];
            foreach ($items as $item) {
                $task = $summaries[$item->taskId()] ?? null;
                if ($item->meeting() === $meeting && $task instanceof TaskSummary && $this->open($task)) {
                    $open[] = $task;
                }
            }
            if ([] !== $open) {
                $groups[] = new CarriedOver($meeting, $open);
            }
        }

        return $groups;
    }

    /**
     * Past its due day and not finished, as the task list marks it.
     */
    public function overdue(TaskSummary $task): bool
    {
        return !$task->done && $task->dueOn instanceof \DateTimeImmutable && $task->dueOn->format('Y-m-d') < $this->clock->today()->format('Y-m-d');
    }

    private function open(TaskSummary $task): bool
    {
        return !$task->done && !$task->trashed;
    }

    private function nearOrOverdue(TaskSummary $task, Meeting $meeting): bool
    {
        if (!$task->dueOn instanceof \DateTimeImmutable) {
            return false;
        }
        $horizon = $meeting->heldOn()->modify(\sprintf('+%d days', self::HORIZON_DAYS))->format('Y-m-d');

        return $this->overdue($task) || $task->dueOn->format('Y-m-d') <= $horizon;
    }
}
