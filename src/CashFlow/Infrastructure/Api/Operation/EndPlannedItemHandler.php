<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Infrastructure\Api\Operation;

use Foresi\CashFlow\Application\EndPlannedItemInput;
use Foresi\CashFlow\Application\PlannedItemWrites;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

final readonly class EndPlannedItemHandler implements OperationHandler
{
    public function __construct(private PlannedItemWrites $writes, private PlannedItemAnswer $answer)
    {
    }

    public static function input(): string
    {
        return EndPlannedItemInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof EndPlannedItemInput);

        return $this->answer->of($this->writes->end($input), 'Ended the planned item %s.');
    }
}
