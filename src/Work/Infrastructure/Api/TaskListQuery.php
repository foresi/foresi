<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Api;

use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Work\Application\DueBucket;
use Foresi\Work\Application\TaskSliceFilter;
use Foresi\Work\Domain\StatusType;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The query string of GET /api/v1/tasks. Every parameter narrows the list.
 */
final readonly class TaskListQuery
{
    public function __construct(
        public string $q = '',
        #[SerializedName('project_id')]
        public ?string $projectId = null,
        // "me" for the signed-in person, or a membership id.
        #[Assert\Regex(pattern: '/^(me|[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})$/', message: 'Use "me" or the id of a person.')]
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
        // A saved view of the list work.my_tasks: its id or system key, such as "work.my_tasks.assigned".
        public ?string $view = null,
        // JSON, {"conditions": [...]}, joined with the view's by AND.
        public ?string $filter = null,
    ) {
    }

    public function filter(?ViewFilter $view = null): TaskSliceFilter
    {
        return new TaskSliceFilter(
            trim($this->q),
            $this->projectId,
            $this->assignee,
            null === $this->statusType ? null : StatusType::from($this->statusType),
            $this->finished,
            null === $this->due ? null : DueBucket::from($this->due),
            $view,
        );
    }
}
