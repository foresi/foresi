<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Application\Api;

use Foresi\Activities\Domain\Activity;
use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

#[Operation(
    name: 'activities.complete',
    method: 'POST',
    path: '/api/v1/activities/{activityId}/complete',
    tool: 'foresi_complete_activity',
    scope: 'activities:write',
    title: 'Mark an activity done, and plan the next',
    description: 'Marks a planned activity done with what came of it. With next_type_id and next_subject it also plans the next step on the same records for the same owner, in the same transaction: if the next one is refused, the activity is not marked done either. One that is done already is refused with already_done.',
    response: 'ActivitiesActivity',
    staffOnly: true,
)]
final readonly class CompleteActivityInput
{
    public function __construct(
        #[Field('The id of the activity.', format: 'uuid')]
        public string $activityId,
        #[Field('When it was done, ISO 8601; now when left out.', format: 'date-time')]
        public ?string $doneAt = null,
        #[Field('What came of it.', maxLength: Activity::MAX_OUTCOME)]
        public ?string $outcome = null,
        #[Field('The type of the next activity to plan; no next one when left out.', format: 'uuid')]
        public ?string $nextTypeId = null,
        #[Field('The subject of the next activity.', maxLength: Activity::MAX_SUBJECT)]
        public ?string $nextSubject = null,
        #[Field('The day the next one is planned on, YYYY-MM-DD, for a type with timing "date".', format: 'date')]
        public ?string $nextPlannedOn = null,
        #[Field('When the next one is planned to start, ISO 8601, for a type with timing "timed".', format: 'date-time')]
        public ?string $nextPlannedAt = null,
        #[Field('How long the next one takes, in minutes; the type\'s default when left out.')]
        public ?int $nextDurationMinutes = null,
    ) {
    }
}
