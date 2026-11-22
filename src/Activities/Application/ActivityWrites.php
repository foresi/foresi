<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Application;

use Foresi\Activities\Application\Api\CompleteActivityInput;
use Foresi\Activities\Application\Api\CreateActivityInput;
use Foresi\Activities\Application\Api\RestoreActivityInput;
use Foresi\Activities\Application\Api\TrashActivityInput;
use Foresi\Activities\Application\Api\UpdateActivityInput;
use Foresi\Activities\Contract\Event\ActivityCreated;
use Foresi\Activities\Contract\Event\ActivityDone;
use Foresi\Activities\Contract\Event\ActivityRestored;
use Foresi\Activities\Contract\Event\ActivityTrashed;
use Foresi\Activities\Contract\Event\ActivityUpdated;
use Foresi\Activities\Domain\Activity;
use Foresi\Activities\Domain\ActivityLink;
use Foresi\Activities\Domain\ActivitySchedule;
use Foresi\Activities\Domain\ActivityType;
use Foresi\Shared\Application\Api\Omitted;
use Foresi\Shared\Application\Event\EventRecorder;

/**
 * Logging and planning activities, changing them, marking them done with the
 * next step planned in the same transaction ("Done & Schedule Next", as Odoo
 * and Pipedrive have it), and the trash. A person changes only an activity they
 * see (ActivityTargets).
 */
final readonly class ActivityWrites
{
    public function __construct(
        private ActivitiesAccess $access,
        private ActivitiesStore $store,
        private ActivitiesTransaction $transaction,
        private ActivityTargets $targets,
        private ActivityTimes $times,
        private EventRecorder $events,
    ) {
    }

    /**
     * @throws ActivitiesRefused
     */
    public function create(CreateActivityInput $input): Activity
    {
        $userId = $this->access->denyUnlessStaff();
        $links = $this->targets->check($input->links);
        $owner = $this->owner($input->ownerUserId ?? $userId);
        $schedule = $this->schedule($input->plannedOn, $input->plannedAt, $input->durationMinutes, 'plannedOn', 'plannedAt');
        $now = $this->times->now();
        $doneAt = $input->done || null !== $input->doneAt ? ($this->times->dateTime($input->doneAt, 'doneAt') ?? $now) : null;

        return $this->transaction->run(function () use ($input, $links, $owner, $schedule, $doneAt, $userId, $now): Activity {
            $type = $this->activeType($input->typeId, 'typeId');
            $activity = ActivitiesInvalid::unless(static fn (): Activity => new Activity($type, $input->subject, $input->body, $schedule, $doneAt, $input->outcome, $owner, $userId, $now));

            return $this->added($activity, $type, $links);
        });
    }

    /**
     * @throws ActivitiesRefused
     */
    public function update(UpdateActivityInput $input): Activity
    {
        $this->access->denyUnlessStaff();
        $links = $input->links instanceof Omitted ? null : $this->targets->check($input->links);
        $owner = $input->ownerUserId instanceof Omitted ? null : $this->owner($input->ownerUserId);
        $plannedOn = $input->plannedOn instanceof Omitted ? $input->plannedOn : $this->times->date($input->plannedOn, 'plannedOn');
        $plannedAt = $input->plannedAt instanceof Omitted ? $input->plannedAt : $this->times->dateTime($input->plannedAt, 'plannedAt');

        return $this->transaction->run(function () use ($input, $links, $owner, $plannedOn, $plannedAt): Activity {
            $activity = $this->visible($input->activityId);
            $type = $input->typeId instanceof Omitted ? $this->typeOf($activity) : $this->activeType($input->typeId, 'typeId');
            $now = $this->times->now();
            // A date given clears the time, and the other way round.
            $on = $plannedOn instanceof Omitted ? ($plannedAt instanceof \DateTimeImmutable ? null : $activity->plannedOn()) : $plannedOn;
            $at = $plannedAt instanceof Omitted ? ($plannedOn instanceof \DateTimeImmutable ? null : $activity->plannedAt()) : $plannedAt;
            $duration = $input->durationMinutes instanceof Omitted ? $activity->durationMinutes() : $input->durationMinutes;
            ActivitiesInvalid::unless(fn () => $activity->change(
                $type,
                $input->subject instanceof Omitted ? $activity->subject() : $input->subject,
                $input->body instanceof Omitted ? $activity->body() : $input->body,
                new ActivitySchedule($on, $at, $duration),
                $input->outcome instanceof Omitted ? $activity->outcome() : $input->outcome,
                $now,
            ));
            if (null !== $owner) {
                $activity->assignTo($owner, $now);
            }
            if (null !== $links) {
                $this->relink($activity, $links);
            }
            $this->events->record(new ActivityUpdated($activity->id(), $type->key(), $this->linkNames($activity), $activity->ownerUserId()));

            return $activity;
        });
    }

    /**
     * Marks it done and, when the input names a next one, plans that on the same
     * records for the same owner. Both happen or neither does.
     *
     * @return array{Activity, Activity|null} the activity, and the next one
     *
     * @throws ActivitiesRefused
     */
    public function complete(CompleteActivityInput $input): array
    {
        $userId = $this->access->denyUnlessStaff();
        $doneAt = $this->times->dateTime($input->doneAt, 'doneAt');
        $next = null === $input->nextTypeId && null === $input->nextSubject
            ? null
            : $this->schedule($input->nextPlannedOn, $input->nextPlannedAt, $input->nextDurationMinutes, 'nextPlannedOn', 'nextPlannedAt');

        return $this->transaction->run(function () use ($input, $doneAt, $next, $userId): array {
            $activity = $this->visible($input->activityId);
            $now = $this->times->now();
            ActivitiesInvalid::unless(static fn () => $activity->complete($doneAt ?? $now, $input->outcome, $now));
            $type = $this->typeOf($activity);
            $this->events->record(new ActivityDone($activity->id(), $type->key(), $this->linkNames($activity), $activity->ownerUserId()));
            if (!$next instanceof ActivitySchedule) {
                return [$activity, null];
            }
            $nextType = $this->activeType((string) $input->nextTypeId, 'nextTypeId');
            $planned = $this->nextOrRefused(static fn (): Activity => new Activity($nextType, (string) $input->nextSubject, '', $next, null, null, $activity->ownerUserId(), $userId, $now));
            $links = array_map(static fn (ActivityLink $link): RecordLink => new RecordLink($link->kind(), $link->targetId()), $this->store->links($activity));

            return [$activity, $this->added($planned, $nextType, $links)];
        });
    }

    /**
     * @throws ActivitiesRefused
     */
    public function trash(TrashActivityInput $input): Activity
    {
        $userId = $this->access->denyUnlessStaff();

        return $this->transaction->run(function () use ($input, $userId): Activity {
            $activity = $this->visible($input->activityId);
            if ($activity->trash($userId, $this->times->now())) {
                $this->events->record(new ActivityTrashed($activity->id(), $this->typeOf($activity)->key(), $this->linkNames($activity), $activity->ownerUserId()));
            }

            return $activity;
        });
    }

    /**
     * @throws ActivitiesRefused
     */
    public function restore(RestoreActivityInput $input): Activity
    {
        $this->access->denyUnlessStaff();

        return $this->transaction->run(function () use ($input): Activity {
            $activity = $this->visible($input->activityId);
            if ($activity->restore($this->times->now())) {
                $this->events->record(new ActivityRestored($activity->id(), $this->typeOf($activity)->key(), $this->linkNames($activity), $activity->ownerUserId()));
            }

            return $activity;
        });
    }

    /**
     * @param list<RecordLink> $links
     */
    private function added(Activity $activity, ActivityType $type, array $links): Activity
    {
        $this->store->add($activity);
        foreach ($links as $link) {
            $this->store->add(new ActivityLink($activity, $link->kind, $link->id));
        }
        $this->events->record(new ActivityCreated($activity->id(), $type->key(), array_map(static fn (RecordLink $link): string => $link->name(), $links), $activity->ownerUserId()));

        return $activity;
    }

    /**
     * @param list<RecordLink> $links
     */
    private function relink(Activity $activity, array $links): void
    {
        foreach ($this->store->links($activity) as $old) {
            $this->store->remove($old);
        }
        // Removals go first, so a link given again is added anew.
        $this->transaction->flush();
        foreach ($links as $link) {
            $this->store->add(new ActivityLink($activity, $link->kind, $link->id));
        }
        $this->transaction->flush();
    }

    /**
     * A refusal of the next activity is reported at its own fields.
     *
     * @param callable(): Activity $next
     *
     * @throws ActivitiesRefused
     */
    private function nextOrRefused(callable $next): Activity
    {
        try {
            return ActivitiesInvalid::unless($next);
        } catch (ActivitiesRefused $refused) {
            $field = $refused->field();

            throw null === $field ? $refused : ActivitiesRefused::invalid($refused->reason(), 'next'.ucfirst($field), $refused->getMessage());
        }
    }

    /**
     * @throws ActivitiesRefused
     */
    private function schedule(?string $plannedOn, ?string $plannedAt, ?int $durationMinutes, string $onField, string $atField): ActivitySchedule
    {
        $on = $this->times->date($plannedOn, $onField);
        $at = $this->times->dateTime($plannedAt, $atField);
        $refused = static fn (string $field): string => 'plannedOn' === $onField ? $field : 'next'.ucfirst($field);

        try {
            return ActivitiesInvalid::unless(static fn (): ActivitySchedule => new ActivitySchedule($on, $at, $durationMinutes));
        } catch (ActivitiesRefused $invalid) {
            throw ActivitiesRefused::invalid($invalid->reason(), $refused((string) $invalid->field()), $invalid->getMessage());
        }
    }

    /**
     * @throws ActivitiesRefused
     */
    private function owner(string $userId): string
    {
        if (!$this->access->isStaffMember($userId)) {
            throw ActivitiesRefused::invalid('owner_unknown', 'ownerUserId', 'An activity belongs to an owner or member of the organisation.');
        }

        return $userId;
    }

    /**
     * @throws ActivitiesRefused
     */
    private function activeType(string $id, string $field): ActivityType
    {
        $type = $this->store->type($id);
        if (!$type instanceof ActivityType || $type->isArchived()) {
            throw ActivitiesRefused::invalid('type_unknown', $field, 'There is no active activity type with this id. List them with foresi_list_activity_types.');
        }

        return $type;
    }

    private function typeOf(Activity $activity): ActivityType
    {
        return $this->store->type($activity->typeId()) ?? throw new \LogicException('An activity has its type.');
    }

    /**
     * An activity the person sees, also one in the trash, so that a change to
     * it is refused as trashed rather than unknown.
     *
     * @throws ActivitiesRefused not_found
     */
    private function visible(string $id): Activity
    {
        $activity = $this->store->activity($id);
        if (!$activity instanceof Activity || !$this->targets->canSee($activity, $this->links($activity), $this->access->userId())) {
            throw ActivitiesRefused::notFound('There is no activity with this id that you may see. Find it on a record\'s timeline with foresi_list_activities.');
        }

        return $activity;
    }

    /**
     * @return list<RecordLink>
     */
    private function links(Activity $activity): array
    {
        return array_map(static fn (ActivityLink $link): RecordLink => new RecordLink($link->kind(), $link->targetId()), $this->store->links($activity));
    }

    /**
     * @return list<string>
     */
    private function linkNames(Activity $activity): array
    {
        return array_map(static fn (RecordLink $link): string => $link->name(), $this->links($activity));
    }
}
