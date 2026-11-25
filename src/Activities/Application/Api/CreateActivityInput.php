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
    name: 'activities.create',
    method: 'POST',
    path: '/api/v1/activities',
    tool: 'foresi_create_activity',
    scope: 'activities:write',
    title: 'Log or plan an activity',
    description: 'Logs a meeting, call, note or task that happened (done: true), or plans one ahead: on a date for a type with timing "date", at a time for a type with timing "timed" (its duration defaults to the type\'s). A note (timing "none") has no time and is done when it is written. Link it to the records it is about, such as {"kind": "contact", "id": "..."}. Types come from foresi_list_activity_types.',
    status: 201,
    response: 'ActivitiesActivity',
    staffOnly: true,
)]
final readonly class CreateActivityInput
{
    /**
     * @param list<mixed> $links
     */
    public function __construct(
        #[Field('The id of the activity type.', format: 'uuid')]
        public string $typeId,
        #[Field('What it is about, such as "Kick-off meeting".', maxLength: Activity::MAX_SUBJECT)]
        public string $subject,
        #[Field('Notes, in Markdown.', maxLength: Activity::MAX_BODY)]
        public string $body = '',
        #[Field('The day it is planned on, YYYY-MM-DD, for a type with timing "date".', format: 'date')]
        public ?string $plannedOn = null,
        #[Field('When it is planned to start, ISO 8601, for a type with timing "timed"; without an offset it is a time of the instance.', format: 'date-time')]
        public ?string $plannedAt = null,
        #[Field('How long it takes, in minutes, for a type with timing "timed"; the type\'s default when left out.')]
        public ?int $durationMinutes = null,
        #[Field('Whether it is done already: a logged activity. A note always is.')]
        public bool $done = false,
        #[Field('When it was done, ISO 8601; now when left out.', format: 'date-time')]
        public ?string $doneAt = null,
        #[Field('What came of it.', maxLength: Activity::MAX_OUTCOME)]
        public ?string $outcome = null,
        #[Field('The records it is about, as objects {kind, id}, such as {"kind": "contact", "id": "..."}. A kind no module offers is refused.', items: Field::OBJECTS)]
        public array $links = [],
        #[Field('The owner or member it belongs to; the caller when left out.', format: 'uuid')]
        public ?string $ownerUserId = null,
    ) {
    }
}
