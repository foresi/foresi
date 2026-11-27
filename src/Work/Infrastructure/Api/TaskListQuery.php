<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Api;

use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Work\Application\DueBucket;
use Foresi\Work\Application\TaskSliceFilter;
use Foresi\Work\Application\View\TaskLists;
use Foresi\Work\Domain\StatusType;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The query string of GET /api/v1/tasks. Every parameter narrows the list; those the list
 * of all tasks has as fields (priority, labels, client, nobody responsible, created and
 * updated) are its conditions, so they mean what its filter means.
 */
final readonly class TaskListQuery
{
    private const string ID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';
    private const string DATE = '/^\d{4}-\d{2}-\d{2}$/';

    public function __construct(
        public string $q = '',
        #[SerializedName('project_id')]
        public ?string $projectId = null,
        // "me" for the signed-in person, "none" for no one, or a membership id.
        #[Assert\Regex(pattern: '/^(me|none|'.self::ID.')$/', message: 'Use "me", "none" or the id of a person.')]
        public ?string $assignee = null,
        #[SerializedName('status_type')]
        #[Assert\Choice(choices: ['open', 'active', 'done', 'closed'])]
        public ?string $statusType = null,
        public ?bool $finished = null,
        #[Assert\Choice(choices: ['overdue', 'today', 'next_7_days', 'none'])]
        public ?string $due = null,
        #[Assert\Range(min: 1, max: 100)]
        public int $limit = 25,
        public ?string $cursor = null,
        // A saved view of the list work.tasks or work.my_tasks: its id or system key, such as "work.tasks.overdue".
        public ?string $view = null,
        // JSON, {"conditions": [...]}, joined with the view's by AND.
        public ?string $filter = null,
        // Priorities, comma separated, such as "high,urgent".
        #[Assert\Regex(pattern: '/^(none|low|medium|high|urgent)(,(none|low|medium|high|urgent))*$/', message: 'Use priorities of none, low, medium, high and urgent, comma separated.')]
        public ?string $priority = null,
        // Label ids, comma separated: a task with any of them.
        #[SerializedName('label_id')]
        #[Assert\Regex(pattern: '/^'.self::ID.'(,'.self::ID.')*$/', message: 'Use label ids, comma separated.')]
        public ?string $labelId = null,
        #[SerializedName('client_id')]
        #[Assert\Regex(pattern: '/^'.self::ID.'$/', message: 'Use the id of a contact.')]
        public ?string $clientId = null,
        #[SerializedName('created_from')]
        #[Assert\Regex(pattern: self::DATE, message: 'Use a date, YYYY-MM-DD.')]
        public ?string $createdFrom = null,
        #[SerializedName('created_to')]
        #[Assert\Regex(pattern: self::DATE, message: 'Use a date, YYYY-MM-DD.')]
        public ?string $createdTo = null,
        #[SerializedName('due_from')]
        #[Assert\Regex(pattern: self::DATE, message: 'Use a date, YYYY-MM-DD.')]
        public ?string $dueFrom = null,
        #[SerializedName('due_to')]
        #[Assert\Regex(pattern: self::DATE, message: 'Use a date, YYYY-MM-DD.')]
        public ?string $dueTo = null,
        #[SerializedName('updated_from')]
        #[Assert\Regex(pattern: self::DATE, message: 'Use a date, YYYY-MM-DD.')]
        public ?string $updatedFrom = null,
        #[SerializedName('updated_to')]
        #[Assert\Regex(pattern: self::DATE, message: 'Use a date, YYYY-MM-DD.')]
        public ?string $updatedTo = null,
        // One of due, priority, created, updated, project, title and my_order; a minus first for backwards. None is the newest first.
        #[Assert\Regex(pattern: '/^-?(due|priority|created|updated|project|title|my_order)$/', message: 'Sort by due, priority, created, updated, project, title or my_order, a minus first for backwards.')]
        public ?string $sort = null,
        // True for the tasks the person starred for My priorities, false for the others.
        public ?bool $starred = null,
    ) {
    }

    /**
     * The conditions of the list of all tasks the parameters name, beside the view's.
     */
    public function conditions(): ViewFilter
    {
        $conditions = [];
        if ('none' === $this->assignee) {
            $conditions[] = new Condition(TaskLists::RESPONSIBLE, 'is_empty');
        }
        if (null !== $this->priority) {
            $conditions[] = new Condition(TaskLists::PRIORITY, 'is_any_of', array_values(array_unique(explode(',', $this->priority))));
        }
        if (null !== $this->labelId) {
            $conditions[] = new Condition(TaskLists::LABELS, 'is_any_of', array_values(array_unique(explode(',', $this->labelId))));
        }
        if (null !== $this->clientId) {
            $conditions[] = new Condition(TaskLists::CLIENT, 'is', $this->clientId);
        }
        if (null !== $this->starred) {
            $conditions[] = new Condition(TaskLists::STARRED, 'is', $this->starred);
        }
        foreach ([[TaskLists::DUE_ON, $this->dueFrom, $this->dueTo], [TaskLists::CREATED, $this->createdFrom, $this->createdTo], [TaskLists::UPDATED, $this->updatedFrom, $this->updatedTo]] as [$field, $from, $to]) {
            if (null !== $from) {
                $conditions[] = new Condition($field, 'on_or_after', $from);
            }
            if (null !== $to) {
                $conditions[] = new Condition($field, 'on_or_before', $to);
            }
        }

        return new ViewFilter($conditions);
    }

    /**
     * @param ViewFilter|null $view the conditions of the view and the filter applied
     * @param bool            $open whether a view of the list of all tasks is applied, which leaves out a finished task unless it asks about the status type
     */
    public function filter(?ViewFilter $view = null, bool $open = false): TaskSliceFilter
    {
        $conditions = $view ?? new ViewFilter();
        foreach ($this->conditions()->conditions as $condition) {
            $conditions = $conditions->with($condition);
        }

        return new TaskSliceFilter(
            trim($this->q),
            $this->projectId,
            'none' === $this->assignee ? null : $this->assignee,
            null === $this->statusType ? null : StatusType::from($this->statusType),
            $this->finished,
            null === $this->due ? null : DueBucket::from($this->due),
            [] === $conditions->conditions ? null : $conditions,
            $open,
            $this->sort ?? '',
        );
    }
}
