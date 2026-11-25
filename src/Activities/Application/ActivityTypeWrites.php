<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Application;

use Foresi\Activities\Application\Api\ArchiveActivityTypeInput;
use Foresi\Activities\Application\Api\CreateActivityTypeInput;
use Foresi\Activities\Application\Api\UpdateActivityTypeInput;
use Foresi\Activities\Domain\ActivityTiming;
use Foresi\Activities\Domain\ActivityType;
use Foresi\Shared\Contract\Operation\Omitted;

/**
 * The kinds of activity the organisation keeps, under Settings → Activities.
 */
final readonly class ActivityTypeWrites
{
    public function __construct(
        private ActivitiesAccess $access,
        private ActivitiesStore $store,
        private ActivitiesTransaction $transaction,
        private ActivityTimes $times,
    ) {
    }

    /**
     * @throws ActivitiesRefused
     */
    public function create(CreateActivityTypeInput $input): ActivityType
    {
        $this->access->denyUnlessStaff();
        $timing = $this->timing($input->timing);

        return $this->transaction->run(function () use ($input, $timing): ActivityType {
            if ($this->store->typeByKey($input->key) instanceof ActivityType) {
                throw ActivitiesRefused::invalid('key_taken', 'key', 'Another activity type has this key.');
            }
            $type = ActivitiesInvalid::unless(fn (): ActivityType => new ActivityType($input->key, $input->name, $timing, $input->defaultDurationMinutes, $this->times->now()));
            $this->store->add($type);

            return $type;
        });
    }

    /**
     * @throws ActivitiesRefused
     */
    public function update(UpdateActivityTypeInput $input): ActivityType
    {
        $this->access->denyUnlessStaff();
        $timing = $input->timing instanceof Omitted ? null : $this->timing($input->timing);

        return $this->transaction->run(function () use ($input, $timing): ActivityType {
            $type = $this->type($input->typeId);
            ActivitiesInvalid::unless(static fn () => $type->change(
                $input->name instanceof Omitted ? $type->name() : $input->name,
                $timing ?? $type->timing(),
                $input->defaultDurationMinutes instanceof Omitted ? $type->defaultDurationMinutes() : $input->defaultDurationMinutes,
            ));

            return $type;
        });
    }

    /**
     * @throws ActivitiesRefused
     */
    public function archive(ArchiveActivityTypeInput $input): ActivityType
    {
        $this->access->denyUnlessStaff();

        return $this->transaction->run(function () use ($input): ActivityType {
            $type = $this->type($input->typeId);
            $type->archive($this->times->now());

            return $type;
        });
    }

    /**
     * @throws ActivitiesRefused not_found
     */
    private function type(string $id): ActivityType
    {
        return $this->store->type($id) ?? throw ActivitiesRefused::notFound('There is no activity type with this id. List them with foresi_list_activity_types.');
    }

    /**
     * @throws ActivitiesRefused invalid
     */
    private function timing(string $timing): ActivityTiming
    {
        return ActivityTiming::tryFrom($timing) ?? throw ActivitiesRefused::invalid('timing_unknown', 'timing', 'The timing is timed, date or none.');
    }
}
