<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application\Api;

use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Operation;
use Foresi\Work\Domain\TimeEntry;

/**
 * A timer from now over the API and MCP, as the time screens start one.
 */
#[Operation(
    name: 'time_entries.start_timer',
    method: 'POST',
    path: '/api/v1/time-entries/timer',
    tool: 'foresi_start_timer',
    scope: 'time_entries:write',
    title: 'Start a timer',
    description: 'Starts a timer of the person from now, on a task they see or on a project without a task. A person runs one timer: one that runs already is stopped and saved first. Stop it with foresi_stop_timer and the id this answers.',
    status: 201,
    response: 'TimeEntry',
)]
final readonly class StartTimerInput
{
    public function __construct(
        #[Field('The project worked on.', format: 'uuid')]
        public string $projectId,
        #[Field('A task of the project; none when left out.', format: 'uuid')]
        public ?string $taskId = null,
        #[Field('What is being done.', maxLength: TimeEntry::MAX_NOTE_LENGTH)]
        public string $note = '',
        #[Field('Whether it is billed to the client; the default of the project when left out.')]
        public ?bool $billable = null,
    ) {
    }
}
