<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Api;

use Foresi\Work\Application\TimeEntrySliceFilter;
use Foresi\Work\Application\WorkRefused;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The query string of GET /api/v1/time-entries. Every parameter narrows the list.
 */
final readonly class TimeEntryListQuery
{
    public function __construct(
        #[SerializedName('project_id')]
        public ?string $projectId = null,
        #[SerializedName('task_id')]
        public ?string $taskId = null,
        // "me" for the signed-in person, or a membership id.
        #[Assert\Regex(pattern: '/^(me|[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})$/', message: 'Use "me" or the id of a person.')]
        public ?string $person = null,
        #[Assert\Date]
        public ?string $from = null,
        #[Assert\Date]
        public ?string $until = null,
        #[Assert\Range(min: 1, max: 100)]
        public int $limit = 25,
        public ?string $cursor = null,
    ) {
    }

    public function filter(): TimeEntrySliceFilter
    {
        return new TimeEntrySliceFilter($this->projectId, $this->taskId, $this->person, $this->day($this->from), $this->day($this->until));
    }

    private function day(?string $value): ?\DateTimeImmutable
    {
        if (null === $value) {
            return null;
        }

        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return false === $day ? throw new WorkRefused('date') : $day;
    }
}
