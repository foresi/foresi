<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Application;

use Foresi\Activities\Contract\ActivityLinks;
use Foresi\Activities\Domain\Activity;
use Foresi\Activities\Domain\ActivityLink;
use Foresi\Shared\Domain\Id;

/**
 * The contract over the links of an activity: one query asks whether the link exists on an
 * activity not in the trash, and only an activity the signed-in person sees answers yes.
 */
final readonly class ActivityLinksForOtherModules implements ActivityLinks
{
    public function __construct(private ActivitiesStore $store, private ActivityTargets $targets, private ActivitiesAccess $access)
    {
    }

    public function isLinked(string $activityId, string $kind, string $id): bool
    {
        if (!Id::isValid($activityId) || !Id::isValid($id) || !$this->store->hasLink($activityId, $kind, $id)) {
            return false;
        }
        $activity = $this->store->activity($activityId);
        if (!$activity instanceof Activity) {
            return false;
        }
        $links = array_map(static fn (ActivityLink $link): RecordLink => new RecordLink($link->kind(), $link->targetId()), $this->store->links($activity));

        return $this->targets->canSee($activity, $links, $this->access->userId());
    }
}
