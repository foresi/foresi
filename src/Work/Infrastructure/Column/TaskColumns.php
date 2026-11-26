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
use Foresi\Work\Application\LabelBadge;
use Foresi\Work\Application\View\TaskLists;
use Foresi\Work\Domain\Priority;
use Foresi\Work\Infrastructure\Controller\ProjectController;
use Foresi\Work\Infrastructure\Controller\TaskEdits;
use Foresi\Work\Infrastructure\Twig\TimeFunctions;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The columns of the list of all tasks; each cell renders one MyTaskLine, a task and the
 * changes its row offers. The title, the project and its client, the status, the person
 * responsible, the due date, the priority and the labels, as a project's list has them, and
 * for whom manages work the estimate and the time logged (the list's context says so, as
 * "manage"). The status, the person, the date and the priority change in the row.
 *
 * Its sorts are TaskLists::SORTS, each under the key of its column; the list's query orders
 * by them, so the expressions here only say the column sorts.
 */
final readonly class TaskColumns implements DataSource
{
    public const string LIST = TaskLists::KEY;
    private const array STATUS_TONES = ['open' => 'gray', 'active' => 'amber', 'done' => 'green', 'closed' => 'gray'];

    public function __construct(
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urls,
        private InstanceClock $clock,
        private TimeFunctions $time,
    ) {
    }

    public function attributes(string $list): iterable
    {
        if (self::LIST !== $list) {
            return [];
        }
        $label = static fn (string $key): string => 'work.task.field.'.$key;
        $manage = static fn (array $context): bool => true === ($context['manage'] ?? false);
        $priorities = array_combine(array_map(static fn (Priority $priority): string => $priority->value, Priority::cases()), array_map(static fn (Priority $priority): string => 'work.priority.'.$priority->value, Priority::cases()));

        return [
            new Attribute('title', $label('title'), AttributeType::Text, static fn (MyTaskLine $line): string => $line->task->title, sort: 't.title', defaultVisible: true, defaultPosition: 10, cell: static fn (MyTaskLine $line): string => $line->task->title, locked: true, truncate: true),
            new Attribute('project', $label('project'), AttributeType::Text, static fn (MyTaskLine $line): string => $line->task->projectName, sort: 'p.name', defaultVisible: true, defaultPosition: 20, cell: $this->project(...), phone: false, truncate: true),
            new Attribute('client', 'work.tasks.field.client', AttributeType::Text, static fn (MyTaskLine $line): ?string => $line->task->clientName, defaultVisible: true, defaultPosition: 25, phone: false, wide: true, truncate: true),
            new Attribute('status', $label('status'), AttributeType::Text, static fn (MyTaskLine $line): string => $line->task->statusName, defaultVisible: true, defaultPosition: 30, cell: static fn (MyTaskLine $line): mixed => TaskEdits::cell(['text' => $line->task->statusName, 'tone' => self::STATUS_TONES[$line->task->statusType->value]], $line->edits->status($line->task))),
            new Attribute('responsible', $label('responsible'), AttributeType::Reference, static fn (MyTaskLine $line): ?string => $line->task->responsibleName, defaultVisible: true, defaultPosition: 40, cell: $this->responsible(...), phone: false, truncate: true),
            new Attribute('due', $label('due_on'), AttributeType::Date, static fn (MyTaskLine $line): ?\DateTimeImmutable => $line->task->dueOn, sort: 't.dueOn', defaultVisible: true, defaultPosition: 50, cell: $this->due(...), phone: false),
            new Attribute('priority', $label('priority'), AttributeType::Enum, static fn (MyTaskLine $line): Priority => $line->task->priority, sort: 't.priority', defaultVisible: true, defaultPosition: 60, options: $priorities, cell: fn (MyTaskLine $line): mixed => TaskEdits::cell(Priority::None === $line->task->priority ? null : $this->translator->trans('work.priority.'.$line->task->priority->value), $line->edits->priority($line->task)), phone: false),
            new Attribute('labels', $label('labels'), AttributeType::Text, static fn (MyTaskLine $line): string => implode(', ', array_map(static fn (LabelBadge $badge): string => $badge->name, $line->task->labels)), defaultVisible: true, defaultPosition: 70, cell: static fn (MyTaskLine $line): ?array => [] === $line->task->labels ? null : ['badges' => array_map(static fn (LabelBadge $badge): array => ['label' => $badge->name, 'tone' => $badge->colour->value], $line->task->labels)], phone: false, wide: true),
            new Attribute('estimate', $label('estimate'), AttributeType::Number, static fn (MyTaskLine $line): ?int => $line->task->estimateMinutes, defaultPosition: 80, cell: fn (MyTaskLine $line): ?string => null === $line->task->estimateMinutes ? null : $this->time->duration($line->task->estimateMinutes), phone: false, available: $manage),
            new Attribute('logged', 'work.tasks.field.logged', AttributeType::Number, static fn (MyTaskLine $line): ?int => $line->task->loggedMinutes, defaultPosition: 90, cell: fn (MyTaskLine $line): ?string => null === $line->task->loggedMinutes || 0 === $line->task->loggedMinutes ? null : $this->time->duration($line->task->loggedMinutes), phone: false, available: $manage),
            new Attribute('created', 'work.tasks.field.created', AttributeType::Date, static fn (MyTaskLine $line): ?\DateTimeImmutable => $line->task->createdAt, sort: 't.createdAt', defaultPosition: 100, cell: $this->date(...), phone: false),
            new Attribute('updated', 'work.tasks.field.updated', AttributeType::Date, static fn (MyTaskLine $line): ?\DateTimeImmutable => $line->task->updatedAt, sort: 't.updatedAt', defaultPosition: 110, cell: fn (MyTaskLine $line): ?string => $this->date($line, true), phone: false),
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
            $due = !$task->done && $task->dueOn->format('Y-m-d') < $this->clock->today()->format('Y-m-d')
                ? ['text' => $this->translator->trans('work.list.overdue', ['date' => $date]), 'tone' => 'red']
                : $date;
        }

        return TaskEdits::cell($due, $line->edits->due($task));
    }

    private function date(MyTaskLine $line, bool $updated = false): ?string
    {
        $date = $updated ? $line->task->updatedAt : $line->task->createdAt;

        return $date?->setTimezone($this->clock->timezone())->format($this->translator->trans('format.date'));
    }
}
