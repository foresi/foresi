<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Column;

use Foresi\Instance\Application\InstanceClock;
use Foresi\Shared\Domain\View\AttributeType;
use Foresi\Ui\Contract\Attribute;
use Foresi\Ui\Contract\DataSource;
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
final readonly class MyTaskColumns implements DataSource
{
    public const string LIST = MyTaskLists::KEY;
    private const array STATUS_TONES = ['open' => 'gray', 'active' => 'amber', 'done' => 'green', 'closed' => 'gray'];

    public function __construct(
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urls,
        private InstanceClock $clock,
    ) {
    }

    public function attributes(string $list): iterable
    {
        if (self::LIST !== $list) {
            return [];
        }
        $label = static fn (string $key): string => 'work.task.field.'.$key;

        return [
            new Attribute('title', $label('title'), AttributeType::Text, static fn (MyTaskLine $line): string => $line->task->title, defaultVisible: true, defaultPosition: 10, cell: static fn (MyTaskLine $line): string => $line->task->title, locked: true, truncate: true),
            new Attribute('starred', $label('starred'), AttributeType::Boolean, static fn (MyTaskLine $line): bool => $line->task->starred, defaultVisible: true, defaultPosition: 15, cell: static fn (MyTaskLine $line): ?array => $line->edits->star($line->task), phone: false),
            new Attribute('project', $label('project'), AttributeType::Text, static fn (MyTaskLine $line): string => $line->task->projectName, defaultVisible: true, defaultPosition: 20, cell: $this->project(...), phone: false, truncate: true),
            new Attribute('status', $label('status'), AttributeType::Text, static fn (MyTaskLine $line): string => $line->task->statusName, defaultVisible: true, defaultPosition: 30, cell: static fn (MyTaskLine $line): mixed => TaskEdits::cell(['text' => $line->task->statusName, 'tone' => self::STATUS_TONES[$line->task->statusType->value]], $line->edits->status($line->task))),
            new Attribute('responsible', $label('responsible'), AttributeType::Reference, static fn (MyTaskLine $line): ?string => $line->task->responsibleName, defaultVisible: true, defaultPosition: 40, cell: $this->responsible(...), phone: false, truncate: true),
            new Attribute('due', $label('due_on'), AttributeType::Date, static fn (MyTaskLine $line): ?\DateTimeImmutable => $line->task->dueOn, defaultVisible: true, defaultPosition: 50, cell: $this->due(...), phone: false),
            new Attribute('priority', $label('priority'), AttributeType::Enum, static fn (MyTaskLine $line): Priority => $line->task->priority, defaultVisible: true, defaultPosition: 60, options: array_combine(array_map(static fn (Priority $priority): string => $priority->value, Priority::cases()), array_map(static fn (Priority $priority): string => 'work.priority.'.$priority->value, Priority::cases())), cell: fn (MyTaskLine $line): mixed => TaskEdits::cell(TaskEdits::priorityBadge($line->task->priority, $this->translator->trans('work.priority.'.$line->task->priority->value)), $line->edits->priority($line->task)), phone: false),
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

        return TaskEdits::cell(null === $task->responsibleId || null === $task->responsibleName ? null : ['avatar' => $line->edits->seed($task->responsibleId), 'user' => $line->edits->user($task->responsibleId), 'text' => $task->responsibleName], $line->edits->responsible($task));
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
