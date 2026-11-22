<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Application;

use Foresi\Activities\Domain\Activity;
use Foresi\Activities\Domain\ActivityLink;
use Foresi\Activities\Domain\ActivityType;
use Foresi\Shared\Domain\Id;

/**
 * What the screens, the REST reads and the MCP tools show of activities: a
 * record's timeline, a person's planned activities, one activity and the types.
 * Only what the person sees (ActivityTargets) is shown.
 */
final readonly class ActivityReads
{
    /** The longest window of "my planned", in days. */
    public const int MAX_WINDOW_DAYS = 400;

    public function __construct(
        private ActivitiesAccess $access,
        private ActivitiesStore $store,
        private ActivityTargets $targets,
        private ActivityTimes $times,
    ) {
    }

    /**
     * The activities of a record, planned first, the soonest on top, then the
     * done ones, the latest first (Timeline). The trash is left out.
     *
     * @return list<Activity>
     *
     * @throws ActivitiesRefused
     */
    public function timeline(string $kind, string $id): array
    {
        $userId = $this->access->denyUnlessStaff();
        if (1 !== preg_match('/^[a-z][a-z0-9_]{0,39}$/', $kind) || !Id::isValid($id)) {
            throw ActivitiesRefused::invalid('link_invalid', 'linked_kind', 'A record is named by its kind, such as "contact", and its id.');
        }
        $activities = $this->store->linkedTo($kind, $id);
        $links = $this->store->linksOf($activities);

        return Timeline::order(array_values(array_filter(
            $activities,
            fn (Activity $activity): bool => $this->targets->canSee($activity, $this->recordLinks($links[$activity->id()] ?? []), $userId),
        )));
    }

    /**
     * The person's own activities that are planned and not done, in [$from, $to),
     * the soonest first.
     *
     * @return list<Activity>
     *
     * @throws ActivitiesRefused
     */
    public function myPlanned(?string $from, ?string $to): array
    {
        $userId = $this->access->denyUnlessStaff();
        $start = $this->times->date($from, 'from') ?? $this->times->now()->setTime(0, 0);
        $end = $this->times->date($to, 'to') ?? $start->modify('+30 days');
        if ($end <= $start || $end > $start->modify('+'.self::MAX_WINDOW_DAYS.' days')) {
            throw ActivitiesRefused::invalid('window_invalid', 'to', \sprintf('The day "to" comes after "from", at most %d days later.', self::MAX_WINDOW_DAYS));
        }

        return Timeline::order($this->store->plannedOf($userId, $start, $end));
    }

    /**
     * One activity the person sees, also one in the trash.
     *
     * @throws ActivitiesRefused
     */
    public function activity(string $id): Activity
    {
        $userId = $this->access->denyUnlessStaff();
        $activity = $this->store->activity($id);
        if (!$activity instanceof Activity || !$this->targets->canSee($activity, $this->links($activity), $userId)) {
            throw ActivitiesRefused::notFound('There is no activity with this id that you may see. Find it on a record\'s timeline with foresi_list_activities.');
        }

        return $activity;
    }

    /**
     * @return list<ActivityType>
     *
     * @throws ActivitiesRefused
     */
    public function types(bool $withArchived = false): array
    {
        $this->access->denyUnlessStaff();

        return $this->store->types($withArchived);
    }

    /**
     * @return list<RecordLink>
     */
    public function links(Activity $activity): array
    {
        return $this->recordLinks($this->store->links($activity));
    }

    /**
     * @param list<ActivityLink> $links
     *
     * @return list<RecordLink>
     */
    private function recordLinks(array $links): array
    {
        return array_map(static fn (ActivityLink $link): RecordLink => new RecordLink($link->kind(), $link->targetId()), $links);
    }
}
