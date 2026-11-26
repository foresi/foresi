<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Infrastructure\Events;

use Foresi\Deals\Contract\SalesRecords;
use Foresi\Deals\Infrastructure\Api\SalesApiController;
use Foresi\Shared\Application\Event\EventSubjectUrl;
use Foresi\Shared\Contract\Event\EventSubject;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Where the deal an event is about is read over the API.
 */
final readonly class SalesSubjectUrls implements EventSubjectUrl
{
    public function __construct(private UrlGeneratorInterface $urls)
    {
    }

    public function module(): string
    {
        return 'sales';
    }

    public function url(EventSubject $subject): ?string
    {
        return SalesRecords::KIND === $subject->type ? $this->urls->generate(SalesApiController::DEAL, ['dealId' => $subject->id], UrlGeneratorInterface::ABSOLUTE_PATH) : null;
    }
}
