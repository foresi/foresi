<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Controller;

use Foresi\Work\Application\TaskRow;
use Foresi\Work\Domain\Priority;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The props of the Ui:InlineEdit of each property of a task on one page, from
 * the choices InlineTaskEdits read for its tasks. A task whose choices are not
 * known, such as one the person no longer sees, has no picker.
 */
final readonly class TaskEdits
{
    public const array STATUS_TONES = ['open' => 'gray', 'active' => 'amber', 'done' => 'green', 'closed' => 'gray'];

    /**
     * The badge of a priority: urgent is red, high amber, the rest gray, as Linear marks
     * only urgent and ClickUp urgent red and high yellow. No priority has no badge.
     */
    public const array PRIORITY_TONES = ['none' => null, 'low' => 'gray', 'medium' => 'gray', 'high' => 'amber', 'urgent' => 'red'];

    /**
     * @param array<string, list<array{value: string, label: string, tone: string}>>               $statuses   by project id
     * @param array<string, list<array{value: string, name: string, seed: string, user: ?string}>> $people     who can be responsible, by task id
     * @param array<string, string>                                                                $seeds      avatar seeds by membership id
     * @param list<array{value: string, label: string, tone: ?string}>                             $priorities
     * @param array<string, string>                                                                $labels     the names of the properties and of their empty values
     */
    public function __construct(
        private UrlGeneratorInterface $urls,
        private string $token,
        private string $return,
        private array $statuses,
        private array $people,
        private array $seeds,
        private array $priorities,
        private array $labels,
    ) {
    }

    /**
     * The avatar seed of a person, as the people screen colours them.
     */
    public function seed(string $membershipId): string
    {
        return $this->seeds[$membershipId] ?? $membershipId;
    }

    /**
     * The user id of a person, whose photo their avatar shows; null for someone who is no user any more.
     */
    public function user(string $membershipId): ?string
    {
        return $this->seeds[$membershipId] ?? null;
    }

    /**
     * A priority as a row, a card and the task page show it: a badge in its tone, none for no priority.
     *
     * @return array{text: string, tone: string}|null
     */
    public static function priorityBadge(Priority $priority, string $label): ?array
    {
        $tone = self::PRIORITY_TONES[$priority->value];

        return null === $tone ? null : ['text' => $label, 'tone' => $tone];
    }

    /**
     * A cell of a Ui:DataTable as it reads, changed in place when it has an edit.
     *
     * @param string|array<string, mixed>|null $value
     * @param array<string, mixed>|null        $edit
     *
     * @return string|array<string, mixed>|null
     */
    public static function cell(string|array|null $value, ?array $edit): string|array|null
    {
        if (null === $edit) {
            return $value;
        }

        return match (true) {
            null === $value => ['edit' => $edit],
            \is_string($value) => ['text' => $value, 'edit' => $edit],
            default => $value + ['edit' => $edit],
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    public function status(TaskRow $task): ?array
    {
        $options = $this->statuses[$task->projectId] ?? null;

        return null === $options || !isset($this->people[$task->id]) ? null : $this->props($task, 'menu', 'app_task_status', 'status', $this->labels['status'], $task->statusId) + ['options' => $options];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function responsible(TaskRow $task): ?array
    {
        $people = $this->people[$task->id] ?? null;
        if (null === $people) {
            return null;
        }
        // Someone no longer active stays shown as long as the task is theirs, as on the task page.
        if (null !== $task->responsibleId && !\in_array($task->responsibleId, array_column($people, 'value'), true)) {
            $people[] = ['value' => $task->responsibleId, 'name' => $task->responsibleName ?? '', 'seed' => $this->seeds[$task->responsibleId] ?? $task->responsibleId, 'user' => $this->seeds[$task->responsibleId] ?? null];
        }

        return $this->props($task, 'person', 'app_task_responsible', 'responsible', $this->labels['responsible'], $task->responsibleId ?? '') + ['people' => $people, 'noneLabel' => $this->labels['nobody']];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function due(TaskRow $task): ?array
    {
        return isset($this->people[$task->id]) ? $this->props($task, 'date', 'app_task_due', 'due', $this->labels['due'], $task->dueOn?->format('Y-m-d') ?? '') + ['emptyLabel' => $this->labels['no_due']] : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function priority(TaskRow $task): ?array
    {
        return isset($this->people[$task->id]) ? $this->props($task, 'menu', 'app_task_priority', 'priority', $this->labels['priority'], $task->priority->value) + ['options' => $this->priorities, 'emptyLabel' => $this->labels['no_priority']] : null;
    }

    /**
     * The star of My priorities: on and off for whoever sees the task, as long as it is not
     * finished, since a finished task leaves the list (plan 37.2).
     *
     * @return array{toggle: array{action: string, token: string, pressed: bool, label: string, icon: string, fields: array<string, string>}}|null
     */
    public function star(TaskRow $task): ?array
    {
        if ($task->done && !$task->starred) {
            return null;
        }

        return ['toggle' => [
            'action' => $this->urls->generate($task->starred ? 'app_task_unstar' : 'app_task_star', ['id' => $task->id]),
            'token' => $this->token,
            'pressed' => $task->starred,
            'label' => $task->starred ? $this->labels['unstar'] : $this->labels['star'],
            'icon' => 'star',
            'fields' => ['return' => $this->return],
        ]];
    }

    /**
     * @return array<string, mixed>
     */
    private function props(TaskRow $task, string $kind, string $route, string $name, string $label, string $value): array
    {
        return [
            'kind' => $kind,
            'action' => $this->urls->generate($route, ['id' => $task->id]),
            'name' => $name,
            'label' => $label,
            'value' => $value,
            'token' => $this->token,
            'fields' => ['return' => $this->return],
        ];
    }
}
