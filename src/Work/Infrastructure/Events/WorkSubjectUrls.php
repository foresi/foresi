<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Events;

use Foresi\Shared\Application\Event\EventSubjectUrl;
use Foresi\Shared\Domain\Event\EventSubject;
use Foresi\Work\Infrastructure\Api\TaskApiController;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Where a task can be read in the API.
 */
final readonly class WorkSubjectUrls implements EventSubjectUrl
{
    public function __construct(private UrlGeneratorInterface $urls)
    {
    }

    public function module(): string
    {
        return 'work';
    }

    public function url(EventSubject $subject): ?string
    {
        return 'task' === $subject->type ? $this->urls->generate(TaskApiController::SHOW, ['taskId' => $subject->id], UrlGeneratorInterface::ABSOLUTE_PATH) : null;
    }
}
