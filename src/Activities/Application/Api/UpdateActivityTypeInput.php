<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Application\Api;

use Foresi\Activities\Domain\ActivityType;
use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Omitted;
use Foresi\Shared\Application\Api\Operation;

#[Operation(
    name: 'activities.types.update',
    method: 'PATCH',
    path: '/api/v1/activity-types/{typeId}',
    tool: 'foresi_update_activity_type',
    scope: 'activities:write',
    title: 'Change an activity type',
    description: 'Changes the name, timing or default duration of a type. Existing activities keep their times; a new timing applies to what is planned or changed from now on.',
    response: 'ActivitiesType',
    staffOnly: true,
)]
final readonly class UpdateActivityTypeInput
{
    public function __construct(
        #[Field('The id of the type.', format: 'uuid')]
        public string $typeId,
        #[Field('The name.', maxLength: ActivityType::MAX_NAME)]
        public string|Omitted $name = new Omitted(),
        #[Field('Which time it has.', enum: ['timed', 'date', 'none'])]
        public string|Omitted $timing = new Omitted(),
        #[Field('For a timed type: the duration of a new activity, in minutes; null for none.')]
        public int|Omitted|null $defaultDurationMinutes = new Omitted(),
    ) {
    }
}
