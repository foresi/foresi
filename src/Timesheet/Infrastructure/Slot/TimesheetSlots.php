<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Timesheet\Infrastructure\Slot;

use Foresi\Identity\Application\OwnerCheck;
use Foresi\Shared\Domain\Currencies;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Infrastructure\Http\TypedAmount;
use Foresi\Timesheet\Application\ProjectTimeSettings;
use Foresi\Timesheet\Application\TaskTime;
use Foresi\Timesheet\Application\TimeEntryRow;
use Foresi\Timesheet\Application\TimeQueries;
use Foresi\Timesheet\Application\TimeTarget;
use Foresi\Timesheet\Domain\ProjectTimeFields;
use Foresi\Timesheet\Infrastructure\InvoiceNumbers;
use Foresi\Ui\Contract\Slot\SlotItem;
use Foresi\Ui\Contract\Slot\SlotProvider;
use Foresi\Ui\Contract\Slot\Slots;
use Foresi\Ui\Format\DurationText;
use Foresi\Work\Application\VisibleWork;
use Foresi\Work\Application\WorkRefused;
use Foresi\Work\Contract\ProjectSlots;
use Foresi\Work\Contract\TaskSlots;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Time on the screens of Work and in the shell: the running timer over every
 * page of a member; on a task the timer as its main action and the time logged
 * on it under its subtasks, with the total against the estimate (Teamwork);
 * on a project "Log time" in its header; for an owner the project's own
 * hourly rate as a card of its settings; and in the dialog of a new project the
 * billable box that follows the client. Work offers the slots and shows the
 * task's to the people of the organisation only.
 */
final readonly class TimesheetSlots implements SlotProvider
{
    public const string TIMER_TEMPLATE = '@Timesheet/task/_timer_action.html.twig';
    public const string TASK_TIME_TEMPLATE = '@Timesheet/task/_time.html.twig';
    public const string LOG_TIME_TEMPLATE = '@Timesheet/projects/_log_time_action.html.twig';
    public const string SETTINGS_TEMPLATE = '@Timesheet/projects/_time_settings.html.twig';
    public const string BILLABLE_DEFAULT_TEMPLATE = '@Timesheet/projects/_billable_default.html.twig';

    public function __construct(
        private TimeQueries $time,
        private InvoiceNumbers $invoiceNumbers,
        private DurationText $duration,
        private TranslatorInterface $translator,
        private OwnerCheck $owner,
        private VisibleWork $work,
        private ProjectTimeSettings $settings,
        private RequestStack $requests,
    ) {
    }

    public function items(string $slot, array $context): iterable
    {
        $taskId = \is_string($context['taskId'] ?? null) ? $context['taskId'] : null;
        $projectId = \is_string($context['projectId'] ?? null) ? $context['projectId'] : null;

        return match (true) {
            Slots::SHELL_WIDGETS === $slot => true === ($context['member'] ?? false) ? [new SlotItem('@Timesheet/_running_timer.html.twig')] : [],
            TaskSlots::HEADER_ACTIONS === $slot && null !== $taskId => $this->timer($taskId),
            TaskSlots::SECTIONS === $slot && null !== $taskId => $this->taskTime($taskId),
            ProjectSlots::HEADER_ACTIONS === $slot && null !== $projectId => $this->time->projectTarget($projectId) instanceof TimeTarget ? [new SlotItem(self::LOG_TIME_TEMPLATE, weight: 10)] : [],
            ProjectSlots::SETTINGS === $slot && null !== $projectId => $this->settingsCard($projectId),
            // The billable box of a new project follows its client; without the address book there is none to follow.
            ProjectSlots::NEW_FORM === $slot && \is_string($context['clientField'] ?? null) => [new SlotItem(self::BILLABLE_DEFAULT_TEMPLATE, ['field' => ProjectTimeFields::BILLABLE_FIELD])],
            default => [],
        };
    }

    /**
     * Start on a task the person may log time on, stop on the one its timer runs on.
     *
     * @return list<SlotItem>
     */
    private function timer(string $taskId): array
    {
        $running = $this->time->running();
        $here = $running instanceof TimeEntryRow && $running->taskId === $taskId;
        if (!$here && !$this->time->taskTarget($taskId) instanceof TimeTarget) {
            return [];
        }

        return [new SlotItem(self::TIMER_TEMPLATE, ['timerHere' => $here], 10)];
    }

    /**
     * @return list<SlotItem>
     */
    private function taskTime(string $taskId): array
    {
        $time = $this->time->ofTask($taskId);
        if (!$time instanceof TaskTime) {
            return [];
        }

        return [new SlotItem(self::TASK_TIME_TEMPLATE, [
            'time' => $this->timeView($time),
            'timeLoggable' => $this->time->taskTarget($taskId) instanceof TimeTarget,
        ], 10)];
    }

    /**
     * The total against the estimate, and each entry newest first.
     *
     * @return array{total: string, entries: list<array<string, mixed>>, empty: bool}
     */
    private function timeView(TaskTime $time): array
    {
        $total = $this->duration->shown($time->totalMinutes);
        $date = $this->translator->trans('format.date');

        return [
            'total' => null === $time->estimateMinutes ? $total : $this->translator->trans('timesheet.time.total_of', ['total' => $total, 'estimate' => $this->duration->shown($time->estimateMinutes)]),
            'empty' => [] === $time->entries,
            'entries' => array_map(fn (TimeEntryRow $entry): array => [
                'id' => $entry->id,
                'date' => $entry->workedOn->format($date),
                'datetime' => $entry->workedOn->format('Y-m-d'),
                'person' => $entry->personName,
                'duration' => null === $entry->minutes ? null : $this->duration->shown($entry->minutes),
                'note' => $entry->note,
                'billable' => $entry->billable,
                'running' => $entry->running,
                'invoiced' => $entry->invoiced,
                'invoice' => null === $entry->invoiceId || null === $entry->invoiceNumber || null === ($href = $this->invoiceNumbers->url($entry->invoiceId)) ? null : ['number' => $entry->invoiceNumber, 'href' => $href],
                'editable' => $entry->editable && !$entry->running,
                'deletable' => $entry->editable,
            ], $time->entries),
        ];
    }

    /**
     * The project's own hourly rate, which only an owner sees and sets.
     *
     * @return list<SlotItem>
     */
    private function settingsCard(string $projectId): array
    {
        if (!$this->owner->isOwner()) {
            return [];
        }
        try {
            $project = $this->work->managedProject($projectId);
        } catch (WorkRefused) {
            return [];
        }
        $rate = $this->settings->hourlyRate($project);
        $locale = $this->requests->getCurrentRequest()?->getLocale() ?? 'en';
        $currencies = Currencies::currentCodes();

        return [new SlotItem(self::SETTINGS_TEMPLATE, [
            'rate' => TypedAmount::field($rate, $locale),
            'rateCurrency' => ($rate?->currency() ?? Currency::of('CZK'))->code(),
            'currencies' => array_combine($currencies, $currencies),
            'archived' => $project->isArchived(),
        ], 10)];
    }
}
