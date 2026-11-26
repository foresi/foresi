<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Timesheet\Application\Api;

use Foresi\Timesheet\Application\ManageTime;
use Foresi\Timesheet\Application\TimeEntryRow;
use Foresi\Timesheet\Application\TimeQueries;
use Foresi\Timesheet\Application\TimeRefused;
use Foresi\Work\Application\WorkRefused;

/**
 * The writes of time over the API and MCP (ADR 0032). Each runs the use case
 * the time screens run, so the same rules decide who may. Every method answers
 * the id of the entry to read back.
 */
final readonly class TimeWrites
{
    public function __construct(private ManageTime $time, private TimeQueries $queries)
    {
    }

    /**
     * @throws TimeRefused|WorkRefused
     */
    public function logTime(LogTimeInput $input): string
    {
        return $this->time->log(
            $input->projectId,
            $input->taskId,
            $this->day($input->workedOn, 'workedOn'),
            $input->minutes,
            $this->moment($input->startedAt, 'startedAt'),
            $this->moment($input->endedAt, 'endedAt'),
            $input->note,
            $input->billable,
        )->id();
    }

    /**
     * @throws TimeRefused|WorkRefused
     */
    public function startTimer(StartTimerInput $input): string
    {
        return $this->time->startTimer($input->projectId, $input->taskId, $input->note, $input->billable)->id();
    }

    /**
     * @throws TimeRefused when the entry is not the running timer of the person
     */
    public function stopTimer(StopTimerInput $input): string
    {
        $running = $this->queries->running();
        if (!$running instanceof TimeEntryRow || $running->id !== $input->entryId) {
            throw new TimeRefused('timer_not_running', 'entryId');
        }

        $this->time->stopTimer();

        return $input->entryId;
    }

    private function day(string $value, string $field): \DateTimeImmutable
    {
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$day instanceof \DateTimeImmutable || $day->format('Y-m-d') !== $value) {
            throw new TimeRefused('date', $field);
        }

        return $day;
    }

    private function moment(?string $value, string $field): ?\DateTimeImmutable
    {
        if (null === $value) {
            return null;
        }
        $moment = \DateTimeImmutable::createFromFormat(\DATE_ATOM, $value);
        if (!$moment instanceof \DateTimeImmutable) {
            throw new TimeRefused('date', $field);
        }

        return $moment;
    }
}
