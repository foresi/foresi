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
use Foresi\Work\Domain\Priority;
use Foresi\Work\Domain\RecurringTask;
use Foresi\Work\Domain\Task;

/**
 * A new recurring task over the API and MCP (ADR 0032), as the dialog of the
 * recurring tasks saves it.
 */
#[Operation(name: 'recurring-tasks.create', method: 'POST', path: '/api/v1/recurring-tasks', tool: 'foresi_create_recurring_task', scope: 'tasks:write', title: 'Create a recurring task', description: 'Sets up a task that comes back on a schedule in a project, such as a weekly slot for small changes: on each date Foresi creates an ordinary task of the project from it, whether or not the last one is done, each date once. '
    .'Check the schedule with the person first; foresi_preview_recurring_task shows the next dates of one saved. '
    .'The schedule is first_on, repeat (day, week, month or year) every N units, optionally on the last day of the month, and end: never, on_date with ends_on, or after_count with ends_after tasks. '
    .'Each task is created create_days_before days before its date (0, on the day, by default) and is due due_days_after days after it, or at the end of its period when left out (a weekly task on a Monday is due on the Sunday). '
    .'Each task gets the title and description, in which the period variables {day}, {week}, {month}, {year}, {quarter} and {month/year} (shifted as {week+1}) name its date, and the section, responsible, priority, estimate, private flag, labels, watchers, custom fields and subtasks as new open ones. '
    .'Find people with foresi_list_project_people and labels with foresi_list_labels. Only the people of the organisation set recurring tasks up; an archived project takes none (archived).', status: 201, response: 'RecurringTaskDetail', staffOnly: true)]
final readonly class CreateRecurringTaskInput
{
    /**
     * @param list<string>                 $labelIds
     * @param list<string>                 $watcherIds
     * @param list<string>                 $subtasks
     * @param array<string, mixed>|Omitted $customFields
     */
    public function __construct(
        #[Field('The project its tasks go to.', format: 'uuid')]
        public string $projectId,
        #[Field('The title of each task; may hold period variables such as {week}.', maxLength: Task::MAX_TITLE_LENGTH)]
        public string $title,
        #[Field('The first date, YYYY-MM-DD.', format: 'date')]
        public string $firstOn,
        #[Field('The unit the schedule repeats in.', enum: ['day', 'week', 'month', 'year'])]
        public string $repeat = 'week',
        #[Field('Every how many units, 1 to 99.')]
        public int $every = 1,
        #[Field('For month and year: on the last day of the month instead of the day of first_on.')]
        public bool $lastDayOfMonth = false,
        #[Field('When the schedule ends: never, on_date (ends_on) or after_count (ends_after tasks).', enum: ['never', 'on_date', 'after_count'])]
        public string $end = 'never',
        #[Field('With end on_date: the last date a task is created for, YYYY-MM-DD.', format: 'date')]
        public ?string $endsOn = null,
        #[Field('With end after_count: how many tasks it creates in all, 1 to 999.')]
        public ?int $endsAfter = null,
        #[Field('How many days before its date each task is created, 0 to 90.')]
        public int $createDaysBefore = 0,
        #[Field('How many days after its date each task is due, 0 to 365; at the end of its period when left out or null.')]
        public ?int $dueDaysAfter = null,
        #[Field('The description of each task, in Markdown; may hold period variables.')]
        public string $description = '',
        #[Field('A section of the project; none when left out.', format: 'uuid')]
        public ?string $sectionId = null,
        #[Field('The person responsible for each task, their id as foresi_list_project_people gives it; nobody when left out.', format: 'uuid')]
        public ?string $responsibleId = null,
        #[Field('How urgent each task is.', enum: ['none', 'low', 'medium', 'high', 'urgent'])]
        public string $priority = Priority::None->value,
        #[Field('Whether each task is private: seen only by whoever set it up, its responsible and its watchers.')]
        public bool $private = false,
        #[Field('The time each task should take, in minutes.')]
        public ?int $estimateMinutes = null,
        #[Field('The labels of each task, by id as foresi_list_labels gives them.', items: 'string')]
        public array $labelIds = [],
        #[Field('The people who follow each task, by their id as foresi_list_project_people gives it; whoever sets it up and the responsible follow anyway.', items: 'string')]
        public array $watcherIds = [],
        #[Field('The titles of the subtasks each task starts with, open, in order; at most '.RecurringTask::MAX_SUBTASKS.'.', items: 'string')]
        public array $subtasks = [],
        #[Field('The custom fields of each task by key, as foresi_list_fields lists them for tasks.', items: Field::ONE_OBJECT)]
        #[CustomFieldsOf(TaskExtension::RECORD)]
        public array|Omitted $customFields = new Omitted(),
    ) {
    }
}
