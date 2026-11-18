<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Infrastructure\Api\Operation;

use Foresi\CashFlow\Application\CreatePlannedItemInput;
use Foresi\CashFlow\Application\PlannedItemWrites;
use Foresi\Shared\Infrastructure\Operation\OperationHandler;
use Foresi\Shared\Infrastructure\Operation\OperationOutput;

final readonly class CreatePlannedItemHandler implements OperationHandler
{
    public function __construct(private PlannedItemWrites $writes, private PlannedItemAnswer $answer)
    {
    }

    public static function input(): string
    {
        return CreatePlannedItemInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof CreatePlannedItemInput);

        return $this->answer->of($this->writes->create($input), 'Created the planned item %s.', created: true);
    }
}
