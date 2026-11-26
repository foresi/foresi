<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application\Api;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;
use Foresi\Work\Domain\TimeEntry;

/**
 * Time worked, logged over the API and MCP as the time screens log it.
 */
#[Operation(
    name: 'time_entries.log',
    method: 'POST',
    path: '/api/v1/time-entries',
    tool: 'foresi_log_time',
    scope: 'time_entries:write',
    title: 'Log time',
    description: 'Logs time the person worked on a day: on a task they see, or on a project without a task where they see all of it. '
        .'Give minutes, or started_at and ended_at (ISO 8601 with the offset) on the day, not both; at least a minute and at most a day (duration). '
        .'billable left out takes the default of the project. Only the owners and team members log time.',
    status: 201,
    response: 'TimeEntry',
)]
final readonly class LogTimeInput
{
    public function __construct(
        #[Field('The project worked on.', format: 'uuid')]
        public string $projectId,
        #[Field('The day worked, YYYY-MM-DD.', format: 'date')]
        public string $workedOn,
        #[Field('A task of the project; none when left out.', format: 'uuid')]
        public ?string $taskId = null,
        #[Field('How long, in minutes.')]
        public ?int $minutes = null,
        #[Field('When the work started, ISO 8601 with the offset.', format: 'date-time')]
        public ?string $startedAt = null,
        #[Field('When the work ended, ISO 8601 with the offset.', format: 'date-time')]
        public ?string $endedAt = null,
        #[Field('What was done.', maxLength: TimeEntry::MAX_NOTE_LENGTH)]
        public string $note = '',
        #[Field('Whether it is billed to the client; the default of the project when left out.')]
        public ?bool $billable = null,
    ) {
    }
}
