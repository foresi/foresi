<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Application;

use Foresi\Activities\Domain\Activity;
use Foresi\Activities\Domain\ActivityLink;
use Foresi\Activities\Domain\ActivityType;

/**
 * Where activities, their links and their types are kept.
 */
interface ActivitiesStore
{
    public function type(string $id): ?ActivityType;

    public function typeByKey(string $key): ?ActivityType;

    /**
     * @return list<ActivityType> in the order they were created
     */
    public function types(bool $withArchived): array;

    public function activity(string $id): ?Activity;

    /**
     * The activities linked to a record, not in the trash.
     *
     * @return list<Activity>
     */
    public function linkedTo(string $kind, string $id): array;

    /**
     * The person's planned activities that are not done or in the trash, planned
     * at a time in [$from, $to) or on a day from $from's to the one before $to's.
     *
     * @return list<Activity>
     */
    public function plannedOf(string $ownerUserId, \DateTimeImmutable $from, \DateTimeImmutable $to): array;

    /**
     * @return list<ActivityLink>
     */
    public function links(Activity $activity): array;

    /**
     * @param list<Activity> $activities
     *
     * @return array<string, list<ActivityLink>> by activity id; an activity without links is left out
     */
    public function linksOf(array $activities): array;

    public function add(ActivityType|Activity|ActivityLink $record): void;

    public function remove(ActivityLink $link): void;
}
