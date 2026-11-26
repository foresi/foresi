<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Application;

use Foresi\Activities\Application\Api\CreateActivityInput;
use Foresi\Activities\Contract\ActivityRecorder;
use Foresi\Activities\Contract\RecordedActivity;
use Foresi\Activities\Domain\ActivityType;

/**
 * The contract over the use cases of ActivityWrites, so another module records an activity under
 * the same access rule, links, owner check, audit and events.
 */
final readonly class RecordsForOtherModules implements ActivityRecorder
{
    public function __construct(private ActivityWrites $writes, private ActivitiesStore $store)
    {
    }

    public function record(RecordedActivity $activity): string
    {
        $type = $this->store->typeByKey($activity->typeKey);
        if (!$type instanceof ActivityType || $type->isArchived()) {
            throw ActivitiesRefused::invalid('type_unknown', 'typeKey', 'There is no active activity type with this key. List them with foresi_list_activity_types.');
        }

        return $this->writes->create(new CreateActivityInput(
            $type->id(),
            $activity->subject,
            $activity->body,
            doneAt: $activity->doneAt->format(\DATE_ATOM),
            links: $activity->links,
            ownerUserId: $activity->ownerUserId,
        ))->id();
    }

    public function accepts(string $typeKey): bool
    {
        $type = $this->store->typeByKey($typeKey);

        return $type instanceof ActivityType && !$type->isArchived();
    }

    public function link(string $activityId, string $kind, string $id): void
    {
        $this->writes->addLink($activityId, $kind, $id);
    }
}
