<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Integration\Calendar;

use Foresi\Activities\Application\ActivitiesStore;
use Foresi\Activities\Application\ActivityTypeNames;
use Foresi\Activities\Domain\Activity;
use Foresi\Activities\Domain\ActivityType;
use Foresi\Activities\Infrastructure\Controller\ActivityController;
use Foresi\Calendar\Contract\CalendarEntry;
use Foresi\Calendar\Contract\CalendarEntrySource;
use Foresi\Calendar\Contract\CalendarUids;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * A person's planned activities in their calendar feeds: what they own, is
 * planned and not done or in the trash. Its owner always sees an activity, so
 * one whose records are gone stays in its owner's feed. The title is "type:
 * subject"; the notes and the records it is about stay in Foresi.
 */
final readonly class PlannedActivityEntries implements CalendarEntrySource
{
    public const string KEY = 'activities.planned';

    public function __construct(
        private ActivitiesStore $store,
        private ActivityTypeNames $names,
        private CalendarUids $uids,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function entriesFor(string $userId, \DateTimeImmutable $from, \DateTimeImmutable $to): iterable
    {
        /** @var array<string, string> $types */
        $types = [];
        foreach ($this->store->plannedOf($userId, $from, $to) as $activity) {
            $types[$activity->typeId()] ??= $this->typeName($activity);
            $at = $activity->plannedAt();
            $on = $activity->plannedOn();
            yield new CalendarEntry(
                $this->uids->of(self::KEY, $activity->id()),
                $types[$activity->typeId()].': '.$activity->subject(),
                $at ?? $on ?? throw new \LogicException('A planned activity has its date or time.'),
                $at instanceof \DateTimeImmutable && null !== $activity->durationMinutes() ? $at->modify('+'.$activity->durationMinutes().' minutes') : null,
                !$at instanceof \DateTimeImmutable,
                $activity->updatedAt(),
                $this->urls->generate(ActivityController::SHOW_ROUTE, ['id' => $activity->id()], UrlGeneratorInterface::ABSOLUTE_URL),
            );
        }
    }

    private function typeName(Activity $activity): string
    {
        $type = $this->store->type($activity->typeId());

        return $type instanceof ActivityType ? $this->names->of($type) : '';
    }
}
