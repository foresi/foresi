<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * A record an activity is about, by its kind and id. The record may go away;
 * the link stays, and the activity is then seen through its other links, or
 * by its owner alone.
 */
#[ORM\Entity]
#[ORM\Table(name: 'activities_activity_link')]
#[ORM\Index(name: 'idx_activities_activity_link_target', columns: ['kind', 'target_id'])]
class ActivityLink
{
    public const int MAX_KIND = 40;

    public function __construct(
        #[ORM\Id]
        #[ORM\ManyToOne(targetEntity: Activity::class)]
        #[ORM\JoinColumn(name: 'activity_id', nullable: false, onDelete: 'CASCADE')]
        private Activity $activity,
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: self::MAX_KIND)]
        private string $kind,
        #[ORM\Id]
        #[ORM\Column(name: 'target_id', type: 'guid')]
        private string $targetId,
    ) {
    }

    public function activityId(): string
    {
        return $this->activity->id();
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function targetId(): string
    {
        return $this->targetId;
    }
}
