<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application\Api;

use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Operation;

/**
 * Stops the timer of the signed-in person over the API and MCP.
 */
#[Operation(
    name: 'time_entries.stop_timer',
    method: 'POST',
    path: '/api/v1/time-entries/{entryId}/stop',
    tool: 'foresi_stop_timer',
    scope: 'time_entries:write',
    title: 'Stop a timer',
    description: 'Stops the running timer of the person: the entry keeps the time from its start until now. The entry is the running one foresi_start_timer answered or foresi_search_time_entries lists with running true; another is refused (timer_not_running).',
    response: 'TimeEntry',
)]
final readonly class StopTimerInput
{
    public function __construct(
        #[Field('The id of the running entry.', format: 'uuid')]
        public string $entryId,
    ) {
    }
}
