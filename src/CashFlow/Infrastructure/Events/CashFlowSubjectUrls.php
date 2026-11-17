<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\CashFlow\Infrastructure\Events;

use App\CashFlow\Infrastructure\Api\CashFlowApiController;
use App\Shared\Application\Event\EventSubjectUrl;
use App\Shared\Domain\Event\EventSubject;
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
