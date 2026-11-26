<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Timesheet\Infrastructure\Events;

use Foresi\Shared\Application\Event\EventSubjectUrl;
use Foresi\Shared\Contract\Event\EventSubject;
use Foresi\Work\Infrastructure\Api\ProjectApiController;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Where the subject of an event of time is read over the API: the project, as
 * for the project's own events.
 */
final readonly class TimesheetSubjectUrls implements EventSubjectUrl
{
    public function __construct(private UrlGeneratorInterface $urls)
    {
    }

    public function module(): string
    {
        return 'timesheet';
    }

    public function url(EventSubject $subject): ?string
    {
        return 'project' === $subject->type
            ? $this->urls->generate(ProjectApiController::SHOW, ['projectId' => $subject->id], UrlGeneratorInterface::ABSOLUTE_PATH)
            : null;
    }
}
