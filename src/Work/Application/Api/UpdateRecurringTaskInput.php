<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application\Api;

use Foresi\Shared\Application\Field\CustomFieldsOf;
use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Omitted;
use Foresi\Shared\Contract\Operation\Operation;
use Foresi\Work\Contract\Field\TaskExtension;
use Foresi\Work\Domain\Task;

/**
 * A partial change of a recurring task over the API and MCP (ADR 0032): a field
 * left out keeps its value, an optional one sent as null is cleared.
 */
#[Operation(name: 'recurring-tasks.update', method: 'PATCH', path: '/api/v1/recurring-tasks/{recurringTaskId}', tool: 'foresi_update_recurring_task', scope: 'tasks:write', title: 'Update a recurring task', description: 'Changes the fields given of a recurring task and keeps the rest; the fields are those of foresi_create_recurring_task. '
    .'The tasks still to come follow the change; those created stay as they are. A new schedule goes on from today, never repeating a date created already. '
    .'label_ids, watcher_ids, subtasks and custom_fields replace what it has. An ended recurring task stays ended (recurring_ended).', response: 'RecurringTaskDetail', staffOnly: true)]
final readonly class UpdateRecurringTaskInput
{
    /**
     * @param list<string>|Omitted         $labelIds
     * @param list<string>|Omitted         $watcherIds
     * @param list<string>|Omitted         $subtasks
     * @param array<string, mixed>|Omitted $customFields
     */
    public function __construct(
        #[Field('The id of the recurring task.', format: 'uuid')]
        public string $recurringTaskId,
        #[Field('The title of each task; may hold period variables.', maxLength: Task::MAX_TITLE_LENGTH)]
        public string|Omitted $title = new Omitted(),
        #[Field('The first date, YYYY-MM-DD.', format: 'date')]
        public string|Omitted $firstOn = new Omitted(),
        #[Field('The unit the schedule repeats in.', enum: ['day', 'week', 'month', 'year'])]
        public string|Omitted $repeat = new Omitted(),
        #[Field('Every how many units, 1 to 99.')]
        public int|Omitted $every = new Omitted(),
        #[Field('For month and year: on the last day of the month.')]
        public bool|Omitted $lastDayOfMonth = new Omitted(),
        #[Field('When the schedule ends: never, on_date (ends_on) or after_count (ends_after tasks).', enum: ['never', 'on_date', 'after_count'])]
        public string|Omitted $end = new Omitted(),
        #[Field('With end on_date: the last date, YYYY-MM-DD.', format: 'date')]
        public string|Omitted|null $endsOn = new Omitted(),
        #[Field('With end after_count: how many tasks in all, 1 to 999.')]
        public int|Omitted|null $endsAfter = new Omitted(),
        #[Field('How many days before its date each task is created, 0 to 90.')]
        public int|Omitted $createDaysBefore = new Omitted(),
        #[Field('How many days after its date each task is due, 0 to 365; null for the end of its period.')]
        public int|Omitted|null $dueDaysAfter = new Omitted(),
        #[Field('The description of each task, in Markdown.')]
        public string|Omitted $description = new Omitted(),
        #[Field('A section of the project; null for none.', format: 'uuid')]
        public string|Omitted|null $sectionId = new Omitted(),
        #[Field('The person responsible for each task; null for nobody.', format: 'uuid')]
        public string|Omitted|null $responsibleId = new Omitted(),
        #[Field('How urgent each task is.', enum: ['none', 'low', 'medium', 'high', 'urgent'])]
        public string|Omitted $priority = new Omitted(),
        #[Field('Whether each task is private.')]
        public bool|Omitted $private = new Omitted(),
        #[Field('The time each task should take, in minutes; null for none.')]
        public int|Omitted|null $estimateMinutes = new Omitted(),
        #[Field('The labels of each task, by id; replaces those it has.', items: 'string')]
        public array|Omitted $labelIds = new Omitted(),
        #[Field('The people who follow each task, by id; replaces those it has.', items: 'string')]
        public array|Omitted $watcherIds = new Omitted(),
        #[Field('The titles of the subtasks each task starts with; replaces those it has.', items: 'string')]
        public array|Omitted $subtasks = new Omitted(),
        #[Field('The custom fields of each task by key; replaces those it has.', items: Field::ONE_OBJECT)]
        #[CustomFieldsOf(TaskExtension::RECORD)]
        public array|Omitted $customFields = new Omitted(),
    ) {
    }
}
