<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Timesheet\Application;

use Foresi\Shared\Contract\RefusalKind;
use Foresi\Shared\Contract\Refused;
use Foresi\Work\Application\WorkRefused;

/**
 * Why time, or a change of a project's time budget, is refused, with the
 * reason the screens translate as timesheet.refused.<reason> and the API and
 * MCP answer as their code. What Work refuses on the way, such as a project the
 * person does not see, stays Work's own refusal.
 */
final class TimeRefused extends \DomainException implements Refused
{
    public const array REASONS = ['unknown', 'not_allowed', 'archived', 'date', 'duration', 'time_note', 'time_task', 'time_not_yours', 'time_invoiced', 'time_task_in_trash', 'timer_running', 'timer_not_running', 'hours_invalid', 'hours_zero', 'budget_below_zero', 'budget_note', 'budget_counting', 'source_not_on_project'];

    /** What the person may not do, whatever they send: 403. */
    private const array FORBIDDEN = ['not_allowed', 'time_not_yours'];

    /** What the data does not allow now: 409. */
    private const array CONFLICTS = ['archived', 'time_invoiced', 'time_task_in_trash', 'timer_running', 'timer_not_running', 'budget_below_zero'];

    /** The input field a reason is about, where it is always the same one. */
    private const array FIELDS = [
        'duration' => 'minutes', 'time_note' => 'note', 'time_task' => 'taskId',
        'hours_invalid' => 'hours', 'hours_zero' => 'hours', 'budget_note' => 'note', 'budget_counting' => 'counting', 'source_not_on_project' => 'sourceActivityId',
    ];

    /** For people and agents; never quoting what someone wrote. */
    private const array MESSAGES = [
        'unknown' => 'No such project, task or time entry, or not one the person sees.',
        'not_allowed' => 'Only the owners and team members of the organisation may do this.',
        'archived' => 'The project is archived; restore it first.',
        'date' => 'A date is YYYY-MM-DD and a moment ISO 8601 with its offset, such as 2026-11-19T09:30:00+01:00.',
        'duration' => 'The time is at least a minute and at most a day: minutes, or a start and an end after it, not both.',
        'time_note' => 'A note of time has at most 2,000 characters.',
        'time_task' => 'The task must be one of the project.',
        'time_not_yours' => 'Only whoever logged the time or an owner of the organisation may change it.',
        'time_invoiced' => 'The time is invoiced and no longer changes.',
        'time_task_in_trash' => 'The task of the time is in the trash; restore it first.',
        'timer_running' => 'The timer is running; stop it first.',
        'timer_not_running' => 'No timer of the person runs.',
        'hours_invalid' => 'Send either hours, a decimal number with at most two decimal places such as "8" or "-2.5", or minutes, a whole number; at most 999,999 minutes either way.',
        'hours_zero' => 'A budget change is not zero.',
        'budget_below_zero' => 'The budget of the project would go below zero.',
        'budget_note' => 'A note of a budget change has at most 500 characters.',
        'budget_counting' => 'Counting is all or billable.',
        'source_not_on_project' => 'The source must be an activity linked to the project.',
    ];

    public function __construct(public readonly string $reason, private readonly ?string $field = null)
    {
        if (!\in_array($reason, self::REASONS, true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown reason "%s".', $reason));
        }
        parent::__construct(self::MESSAGES[$reason]);
    }

    /**
     * The translation key of a refusal as the time screens show it: this one's own, or Work's for what Work refused.
     */
    public static function translationKey(self|WorkRefused $refused): string
    {
        return ($refused instanceof self ? 'timesheet.refused.' : 'work.refused.').$refused->reason;
    }

    /**
     * The code of the API: unknown and not_allowed as every module answers them, not_found and forbidden.
     */
    public function reason(): string
    {
        return match ($this->reason) {
            'unknown' => 'not_found',
            'not_allowed' => 'forbidden',
            default => $this->reason,
        };
    }

    public function kind(): RefusalKind
    {
        return match (true) {
            'unknown' === $this->reason => RefusalKind::NotFound,
            \in_array($this->reason, self::FORBIDDEN, true) => RefusalKind::Forbidden,
            \in_array($this->reason, self::CONFLICTS, true) => RefusalKind::Conflict,
            default => RefusalKind::Invalid,
        };
    }

    public function field(): ?string
    {
        return $this->field ?? self::FIELDS[$this->reason] ?? null;
    }

    public function details(): array
    {
        return [];
    }
}
