<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Application\Api;

use Foresi\Activities\Domain\ActivityType;
use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

#[Operation(
    name: 'activities.types.create',
    method: 'POST',
    path: '/api/v1/activity-types',
    tool: 'foresi_create_activity_type',
    scope: 'activities:write',
    title: 'Create an activity type',
    description: 'Adds a kind of activity, such as a site visit, with its timing: "timed" (a start time and a duration, such as a meeting), "date" (a day, such as a task) or "none" (no time, done when written, such as a note). Its key never changes.',
    status: 201,
    response: 'ActivitiesType',
    staffOnly: true,
)]
final readonly class CreateActivityTypeInput
{
    public function __construct(
        #[Field('The name, such as "Site visit".', maxLength: ActivityType::MAX_NAME)]
        public string $name,
        #[Field('A lowercase identifier, unique and never changed, such as "site_visit".', maxLength: 40)]
        public string $key,
        #[Field('Which time it has.', enum: ['timed', 'date', 'none'])]
        public string $timing,
        #[Field('For a timed type: the duration of a new activity, in minutes.')]
        public ?int $defaultDurationMinutes = null,
    ) {
    }
}
