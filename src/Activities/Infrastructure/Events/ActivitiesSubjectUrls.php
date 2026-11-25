<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Infrastructure\Events;

use Foresi\Activities\Infrastructure\Api\ActivitiesRoutes;
use Foresi\Shared\Application\Event\EventSubjectUrl;
use Foresi\Shared\Contract\Event\EventSubject;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The REST address of the activity an event is about.
 */
final readonly class ActivitiesSubjectUrls implements EventSubjectUrl
{
    public function __construct(private UrlGeneratorInterface $urls)
    {
    }

    public function module(): string
    {
        return 'activities';
    }

    public function url(EventSubject $subject): ?string
    {
        return 'activity' === $subject->type ? $this->urls->generate(ActivitiesRoutes::ACTIVITY, ['activityId' => $subject->id], UrlGeneratorInterface::ABSOLUTE_PATH) : null;
    }
}
