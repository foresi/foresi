<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Infrastructure\Events;

use Foresi\CashFlow\Infrastructure\Api\CashFlowApiController;
use Foresi\Shared\Application\Event\EventSubjectUrl;
use Foresi\Shared\Contract\Event\EventSubject;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Where a planned item can be read in the API.
 */
final readonly class CashFlowSubjectUrls implements EventSubjectUrl
{
    public function __construct(private UrlGeneratorInterface $urls)
    {
    }

    public function module(): string
    {
        return 'cash-flow';
    }

    public function url(EventSubject $subject): ?string
    {
        return 'planned_item' === $subject->type ? $this->urls->generate(CashFlowApiController::PLANNED_ITEM, ['plannedItemId' => $subject->id], UrlGeneratorInterface::ABSOLUTE_PATH) : null;
    }
}
