<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Infrastructure\Controller;

use App\Identity\Application\People;
use App\Identity\Application\Person;
use App\Work\Application\Choice;
use App\Work\Application\StatusChoice;
use App\Work\Application\TaskRow;
use App\Work\Application\WorkQueries;
use App\Work\Domain\Priority;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The pickers of the rows of a list and the cards of a board, which change the
 * status, the responsible person, the due date and the priority of a task where it
 * is shown (Ui:InlineEdit). Each posts to the route of that property on the task
 * page, with the page to come back to; the task page offers the same choices.
 */
final readonly class InlineTaskEdits
{
    public function __construct(
        private WorkQueries $queries,
        private People $people,
        private UrlGeneratorInterface $urls,
        private CsrfTokenManagerInterface $tokens,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * The choices of the given tasks, read once for all of them.
     *
     * @param list<TaskRow> $tasks  every task the page shows, subtasks too
     * @param string        $return the page to come back to after a change, as TaskController accepts it
     */
    public function for(array $tasks, string $return): TaskEdits
    {
        $seeds = array_column(array_map(static fn (Person $person): array => [$person->membershipId, $person->userId], $this->people->all()), 1, 0);
        $priorities = array_map(fn (Priority $priority): array => ['value' => $priority->value, 'label' => $this->translator->trans('work.priority.'.$priority->value)], Priority::cases());

        return new TaskEdits(
            $this->urls,
            $this->tokens->getToken(TaskController::TOKEN)->getValue(),
            $return,
            array_map(static fn (array $statuses): array => array_map(static fn (StatusChoice $status): array => [
                'value' => $status->id,
                'label' => $status->name,
                'tone' => TaskEdits::STATUS_TONES[$status->type->value],
            ], $statuses), $this->queries->statusesOf(array_values(array_unique(array_map(static fn (TaskRow $task): string => $task->projectId, $tasks))))),
            array_map(static fn (array $people): array => array_map(static fn (Choice $person): array => [
                'value' => $person->id,
                'name' => $person->name,
                'seed' => $seeds[$person->id] ?? $person->id,
            ], $people), $this->queries->assignable(array_map(static fn (TaskRow $task): string => $task->id, $tasks))),
            $seeds,
            $priorities,
            [
                'status' => $this->translator->trans('work.task.field.status'),
                'responsible' => $this->translator->trans('work.task.field.responsible'),
                'due' => $this->translator->trans('work.task.field.due_on'),
                'priority' => $this->translator->trans('work.task.field.priority'),
                'nobody' => $this->translator->trans('work.task.nobody'),
                'no_due' => $this->translator->trans('work.task.no_due'),
                'no_priority' => $this->translator->trans('work.priority.none'),
            ],
        );
    }
}
