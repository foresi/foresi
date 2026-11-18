<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Column;

use Foresi\Instance\Application\InstanceClock;
use Foresi\Ui\Contract\Column;
use Foresi\Ui\Contract\ListColumns;
use Foresi\Work\Application\View\MyTaskLists;
use Foresi\Work\Domain\Priority;
use Foresi\Work\Infrastructure\Controller\ProjectController;
use Foresi\Work\Infrastructure\Controller\TaskEdits;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The columns of My tasks; each cell renders one MyTaskLine: the title, the project (a
 * link when the project is seen), and the status, the responsible person, the due date
 * and the priority, each of which changes in the row.
 */
final readonly class MyTaskColumns implements ListColumns
{
    public const string LIST = MyTaskLists::KEY;
    private const array STATUS_TONES = ['open' => 'gray', 'active' => 'amber', 'done' => 'green', 'closed' => 'gray'];

    public function __construct(
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urls,
        private InstanceClock $clock,
    ) {
    }

    public function columns(string $list): iterable
    {
        if (self::LIST !== $list) {
            return [];
        }
        $label = static fn (string $key): string => 'work.task.field.'.$key;

        return [
            new Column('title', $label('title'), static fn (MyTaskLine $line): string => $line->task->title, defaultPosition: 10, locked: true, truncate: true),
            new Column('project', $label('project'), $this->project(...), defaultPosition: 20, phone: false, truncate: true),
            new Column('status', $label('status'), static fn (MyTaskLine $line): mixed => TaskEdits::cell(['text' => $line->task->statusName, 'tone' => self::STATUS_TONES[$line->task->statusType->value]], $line->edits->status($line->task)), defaultPosition: 30),
            new Column('responsible', $label('responsible'), $this->responsible(...), defaultPosition: 40, phone: false, truncate: true),
            new Column('due', $label('due_on'), $this->due(...), defaultPosition: 50, phone: false),
            new Column('priority', $label('priority'), fn (MyTaskLine $line): mixed => TaskEdits::cell(Priority::None === $line->task->priority ? null : $this->translator->trans('work.priority.'.$line->task->priority->value), $line->edits->priority($line->task)), defaultPosition: 60, phone: false),
        ];
    }

    /**
     * A project known only by its name, for a task shared on its own, is not a link.
     *
     * @return string|array{links: list<array{text: string, href: string}>}
     */
    private function project(MyTaskLine $line): string|array
    {
        $task = $line->task;

        return $task->projectSeen ? ['links' => [['text' => $task->projectName, 'href' => $this->urls->generate(ProjectController::PROJECT_ROUTE, ['id' => $task->projectId])]]] : $task->projectName;
    }

    private function responsible(MyTaskLine $line): mixed
    {
        $task = $line->task;

        return TaskEdits::cell(null === $task->responsibleId || null === $task->responsibleName ? null : ['avatar' => $line->edits->seed($task->responsibleId), 'text' => $task->responsibleName], $line->edits->responsible($task));
    }

    private function due(MyTaskLine $line): mixed
    {
        $task = $line->task;
        $due = null;
        if ($task->dueOn instanceof \DateTimeImmutable) {
            $date = $task->dueOn->format($this->translator->trans('format.date'));
            $due = $task->dueOn->format('Y-m-d') < $this->clock->today()->format('Y-m-d')
                ? ['text' => $this->translator->trans('work.list.overdue', ['date' => $date]), 'tone' => 'red']
                : $date;
        }

        return TaskEdits::cell($due, $line->edits->due($task));
    }
}
