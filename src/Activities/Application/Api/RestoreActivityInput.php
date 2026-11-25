<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Application\Api;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

#[Operation(
    name: 'activities.restore',
    method: 'POST',
    path: '/api/v1/activities/{activityId}/restore',
    tool: 'foresi_restore_activity',
    scope: 'activities:write',
    title: 'Restore an activity',
    description: 'Brings an activity back from the trash as it was. Restoring one that is not in the trash changes nothing.',
    response: 'ActivitiesActivity',
    staffOnly: true,
)]
final readonly class RestoreActivityInput
{
    public function __construct(
        #[Field('The id of the activity.', format: 'uuid')]
        public string $activityId,
    ) {
    }
}
