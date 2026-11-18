<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Column;

use Foresi\Instance\Application\InstanceClock;
use Foresi\Ui\Contract\Column;
use Foresi\Ui\Contract\ListColumns;
use Foresi\Work\Application\LabelBadge;
use Foresi\Work\Application\TaskRow;
use Foresi\Work\Application\View\ProjectTaskLists;
use Foresi\Work\Domain\Priority;
use Foresi\Work\Infrastructure\Controller\TaskEdits;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The columns of a project's task list; each cell renders one TaskRow, a task or a
 * subtask: the title with the badge of a private task, the status, the responsible
 * person, the due date, the priority and the labels. The status, the person, the date
 * and the priority change in the row: the list's context holds the pickers of its
 * tasks (`edits`, none in an archived project) and the avatar colours (`seeds`).
 */
final readonly class ProjectTaskColumns implements ListColumns
{
    public const string LIST = ProjectTaskLists::KEY;
    public const string EDITS = 'edits';
    public const string SEEDS = 'seeds';

    public function __construct(private TranslatorInterface $translator, private InstanceClock $clock)
    {
    }

    public function columns(string $list): iterable
    {
        if (self::LIST !== $list) {
            return [];
        }
        $label = static fn (string $key): string => 'work.task.field.'.$key;

        return [
            new Column('title', $label('title'), $this->title(...), defaultPosition: 10, locked: true, truncate: true),
            new Column('status', $label('status'), $this->status(...), defaultPosition: 20),
            new Column('responsible', $label('responsible'), $this->responsible(...), defaultPosition: 30, phone: false, truncate: true),
            new Column('due', $label('due_on'), $this->dueCell(...), defaultPosition: 40, phone: false),
            new Column('priority', $label('priority'), $this->priority(...), defaultPosition: 50, phone: false),
            new Column('labels', $label('labels'), static fn (TaskRow $task): ?array => [] === $task->labels ? null : ['badges' => array_map(static fn (LabelBadge $badge): array => ['label' => $badge->name, 'tone' => $badge->colour->value], $task->labels)], defaultPosition: 60, phone: false, wide: true),
        ];
    }

    /**
     * A plain date; an unfinished task past it says so in words on the red tone.
     *
     * @return string|array{text: string, tone: string}|null
     */
    public function due(TaskRow $task): string|array|null
    {
        if (!$task->dueOn instanceof \DateTimeImmutable) {
            return null;
        }
        $date = $task->dueOn->format($this->translator->trans('format.date'));
        if (!$task->done && $task->dueOn->format('Y-m-d') < $this->clock->today()->format('Y-m-d')) {
            return ['text' => $this->translator->trans('work.list.overdue', ['date' => $date]), 'tone' => 'red'];
        }

        return $date;
    }

    /**
     * @return string|array{text: string, flag: array{label: string, tone: string, icon: string}}
     */
    private function title(TaskRow $task): string|array
    {
        // Only the people a private task is shared with see it: its badge says so.
        return $task->private ? ['text' => $task->title, 'flag' => ['label' => $this->translator->trans('work.sharing.private_badge'), 'tone' => 'gray', 'icon' => 'lock']] : $task->title;
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return string|array<string, mixed>|null
     */
    private function status(TaskRow $task, array $context): string|array|null
    {
        return TaskEdits::cell(['text' => $task->statusName, 'tone' => TaskEdits::STATUS_TONES[$task->statusType->value]], $this->edits($context)?->status($task));
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return string|array<string, mixed>|null
     */
    private function dueCell(TaskRow $task, array $context): string|array|null
    {
        return TaskEdits::cell($this->due($task), $this->edits($context)?->due($task));
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return string|array<string, mixed>|null
     */
    private function priority(TaskRow $task, array $context): string|array|null
    {
        return TaskEdits::cell(Priority::None === $task->priority ? null : $this->translator->trans('work.priority.'.$task->priority->value), $this->edits($context)?->priority($task));
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return string|array<string, mixed>|null
     */
    private function responsible(TaskRow $task, array $context): string|array|null
    {
        $seeds = $context[self::SEEDS] ?? [];
        $person = null === $task->responsibleId || null === $task->responsibleName ? null : [
            'avatar' => \is_array($seeds) && \is_string($seeds[$task->responsibleId] ?? null) ? $seeds[$task->responsibleId] : $task->responsibleId,
            'text' => $task->responsibleName,
        ];

        return TaskEdits::cell($person, $this->edits($context)?->responsible($task));
    }

    /**
     * @param array<string, mixed> $context
     */
    private function edits(array $context): ?TaskEdits
    {
        $edits = $context[self::EDITS] ?? null;

        return $edits instanceof TaskEdits ? $edits : null;
    }
}
