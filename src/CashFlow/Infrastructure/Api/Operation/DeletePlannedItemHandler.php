<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Infrastructure\Api\Operation;

use Foresi\CashFlow\Application\DeletePlannedItemInput;
use Foresi\CashFlow\Application\PlannedItemWrites;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

final readonly class DeletePlannedItemHandler implements OperationHandler
{
    public function __construct(private PlannedItemWrites $writes)
    {
    }

    public static function input(): string
    {
        return DeletePlannedItemInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof DeletePlannedItemInput);

        return new OperationOutput(['id' => $this->writes->delete($input), 'deleted' => true], 'Deleted the planned item.');
    }
}
