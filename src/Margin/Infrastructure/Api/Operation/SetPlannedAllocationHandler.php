<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Infrastructure\Api\Operation;

use Foresi\Margin\Application\AllocationWrites;
use Foresi\Margin\Application\SetPlannedAllocationInput;
use Foresi\Margin\Infrastructure\Api\MarginJson;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

/**
 * margin.planned_allocations.set: the versions of the mapping of the planned item.
 */
final readonly class SetPlannedAllocationHandler implements OperationHandler
{
    public function __construct(private AllocationWrites $writes)
    {
    }

    public static function input(): string
    {
        return SetPlannedAllocationInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof SetPlannedAllocationInput);

        return new OperationOutput(MarginJson::planned($this->writes->setPlannedAllocation($input)), null === $input->allocation ? 'Ended the mapping of the planned item.' : 'Set the mapping of the planned item.');
    }
}
