<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Application;

use Foresi\Activities\Contract\LinkableRecords;
use Foresi\Activities\Contract\LinkedRecord;
use Foresi\Activities\Domain\Activity;
use Foresi\Activities\Domain\ActivityLink;
use Foresi\Shared\Domain\Id;

/**
 * The contract over ActivityTargets, so another module asks the same questions Activities' own
 * use cases ask before they link a record or show an activity.
 */
final readonly class LinkableRecordsForOtherModules implements LinkableRecords
{
    public function __construct(private ActivityTargets $targets, private ActivitiesStore $store, private ActivitiesAccess $access)
    {
    }

    public function kinds(): array
    {
        return $this->targets->kinds();
    }

    public function canLink(string $kind, string $id): bool
    {
        // check() refuses an id that is none and a kind no module offers, as it does a record that cannot be linked.
        try {
            $this->targets->check([['kind' => $kind, 'id' => $id]]);
        } catch (ActivitiesRefused) {
            return false;
        }

        return true;
    }

    public function describe(string $kind, string $id): ?LinkedRecord
    {
        $view = $this->targets->describe(new RecordLink($kind, $id));

        return $view->available ? new LinkedRecord($kind, $id, $view->label, $view->url) : null;
    }

    public function seesActivity(string $activityId): bool
    {
        $activity = Id::isValid($activityId) ? $this->store->activity($activityId) : null;
        if (!$activity instanceof Activity || $activity->isTrashed()) {
            return false;
        }

        return $this->targets->canSee($activity, $this->links($activity), $this->access->userId());
    }

    public function isLinked(string $activityId): bool
    {
        $activity = Id::isValid($activityId) ? $this->store->activity($activityId) : null;

        return $activity instanceof Activity && [] !== $this->store->links($activity);
    }

    /**
     * @return list<RecordLink>
     */
    private function links(Activity $activity): array
    {
        return array_map(static fn (ActivityLink $link): RecordLink => new RecordLink($link->kind(), $link->targetId()), $this->store->links($activity));
    }
}
