<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Infrastructure\Events;

use Foresi\Meetings\Infrastructure\Api\MeetingsApiController;
use Foresi\Shared\Application\Event\EventSubjectUrl;
use Foresi\Shared\Contract\Event\EventSubject;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Where the meeting or the series an event is about is read over the API.
 */
final readonly class MeetingsSubjectUrls implements EventSubjectUrl
{
    public function __construct(private UrlGeneratorInterface $urls)
    {
    }

    public function module(): string
    {
        return 'meetings';
    }

    public function url(EventSubject $subject): ?string
    {
        return match ($subject->type) {
            'meeting' => $this->urls->generate(MeetingsApiController::MEETING, ['meetingId' => $subject->id], UrlGeneratorInterface::ABSOLUTE_PATH),
            'meeting_series' => $this->urls->generate(MeetingsApiController::ONE_SERIES, ['seriesId' => $subject->id], UrlGeneratorInterface::ABSOLUTE_PATH),
            default => null,
        };
    }
}
