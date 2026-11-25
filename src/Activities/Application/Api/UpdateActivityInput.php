<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Application\Api;

use Foresi\Activities\Domain\Activity;
use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Omitted;
use Foresi\Shared\Contract\Operation\Operation;

#[Operation(
    name: 'activities.update',
    method: 'PATCH',
    path: '/api/v1/activities/{activityId}',
    tool: 'foresi_update_activity',
    scope: 'activities:write',
    title: 'Change an activity',
    description: 'Changes what is given: the type, subject, notes, planned date or time, duration, outcome, owner or links. Giving planned_on clears planned_at and the other way round. An activity in the trash is refused with trashed; mark one done with foresi_complete_activity.',
    response: 'ActivitiesActivity',
    staffOnly: true,
)]
final readonly class UpdateActivityInput
{
    /**
     * @param list<mixed>|Omitted $links
     */
    public function __construct(
        #[Field('The id of the activity.', format: 'uuid')]
        public string $activityId,
        #[Field('The id of the activity type.', format: 'uuid')]
        public string|Omitted $typeId = new Omitted(),
        #[Field('What it is about.', maxLength: Activity::MAX_SUBJECT)]
        public string|Omitted $subject = new Omitted(),
        #[Field('Notes, in Markdown.', maxLength: Activity::MAX_BODY)]
        public string|Omitted $body = new Omitted(),
        #[Field('The day it is planned on, YYYY-MM-DD; null for none.', format: 'date')]
        public string|Omitted|null $plannedOn = new Omitted(),
        #[Field('When it is planned to start, ISO 8601; null for none.', format: 'date-time')]
        public string|Omitted|null $plannedAt = new Omitted(),
        #[Field('How long it takes, in minutes; null for the type\'s default.')]
        public int|Omitted|null $durationMinutes = new Omitted(),
        #[Field('What came of it; null for nothing.', maxLength: Activity::MAX_OUTCOME)]
        public string|Omitted|null $outcome = new Omitted(),
        #[Field('The records it is about, as objects {kind, id}. Replaces all links.', items: Field::OBJECTS)]
        public array|Omitted $links = new Omitted(),
        #[Field('The owner or member it belongs to.', format: 'uuid')]
        public string|Omitted $ownerUserId = new Omitted(),
    ) {
    }
}
