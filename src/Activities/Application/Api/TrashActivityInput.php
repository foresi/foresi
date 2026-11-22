<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Application\Api;

use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Operation;

#[Operation(
    name: 'activities.trash',
    method: 'POST',
    path: '/api/v1/activities/{activityId}/trash',
    tool: 'foresi_trash_activity',
    scope: 'activities:write',
    title: 'Put an activity in the trash',
    description: 'Puts an activity in the trash: it leaves every timeline and calendar feed and keeps everything; restore it with foresi_restore_activity. Trashing it again changes nothing.',
    destructive: true,
    response: 'ActivitiesActivity',
    staffOnly: true,
)]
final readonly class TrashActivityInput
{
    public function __construct(
        #[Field('The id of the activity.', format: 'uuid')]
        public string $activityId,
    ) {
    }
}
