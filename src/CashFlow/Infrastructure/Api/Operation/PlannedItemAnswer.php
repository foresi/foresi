<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Infrastructure\Api\Operation;

use Foresi\CashFlow\Application\CashFlowAccess;
use Foresi\CashFlow\Application\PlannedItemVatRule;
use Foresi\CashFlow\Domain\PlannedItem;
use Foresi\CashFlow\Infrastructure\Api\CashFlowApiController;
use Foresi\CashFlow\Infrastructure\Api\CashFlowJson;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Shared\Contract\Operation\OperationOutput;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * What a planned-item operation answers: the item as GET reads it.
 */
final readonly class PlannedItemAnswer
{
    public function __construct(private InstanceClock $clock, private UrlGeneratorInterface $urls, private CashFlowAccess $access, private PlannedItemVatRule $vat)
    {
    }

    public function of(PlannedItem $item, string $summary, bool $created = false): OperationOutput
    {
        return new OperationOutput(
            CashFlowJson::plannedItem($item, $this->clock->timezone(), $this->access->firm(), $this->vat->isMissing($item)),
            \sprintf($summary, $item->name()),
            $created ? $this->urls->generate(CashFlowApiController::PLANNED_ITEM, ['plannedItemId' => $item->id()]) : null,
        );
    }
}
