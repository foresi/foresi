<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\CashFlow\Infrastructure\Api\Operation;

use App\CashFlow\Domain\PlannedItem;
use App\CashFlow\Infrastructure\Api\CashFlowApiController;
use App\CashFlow\Infrastructure\Api\CashFlowJson;
use App\Instance\Application\TenantClock;
use App\Shared\Infrastructure\Operation\OperationOutput;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * What a planned-item operation answers: the item as GET reads it.
 */
final readonly class PlannedItemAnswer
{
    public function __construct(private TenantClock $clock, private UrlGeneratorInterface $urls)
    {
    }

    public function of(PlannedItem $item, string $summary, bool $created = false): OperationOutput
    {
        return new OperationOutput(
            CashFlowJson::plannedItem($item, $this->clock->timezone()),
            \sprintf($summary, $item->name()),
            $created ? $this->urls->generate(CashFlowApiController::PLANNED_ITEM, ['plannedItemId' => $item->id()]) : null,
        );
    }
}
