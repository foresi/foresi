<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Application\Api;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

#[Operation(
    name: 'activities.types.archive',
    method: 'POST',
    path: '/api/v1/activity-types/{typeId}/archive',
    tool: 'foresi_archive_activity_type',
    scope: 'activities:write',
    title: 'Archive an activity type',
    description: 'Stops offering a type for new activities; the activities of that type keep it. Archiving it again changes nothing.',
    destructive: true,
    response: 'ActivitiesType',
    staffOnly: true,
)]
final readonly class ArchiveActivityTypeInput
{
    public function __construct(
        #[Field('The id of the type.', format: 'uuid')]
        public string $typeId,
    ) {
    }
}
