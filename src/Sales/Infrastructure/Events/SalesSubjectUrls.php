<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Infrastructure\Events;

use Foresi\Sales\Contract\SalesRecords;
use Foresi\Sales\Infrastructure\Api\SalesApiController;
use Foresi\Shared\Application\Event\EventSubjectUrl;
use Foresi\Shared\Domain\Event\EventSubject;
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
