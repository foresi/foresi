<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Infrastructure\Api\Operation;

use Foresi\Sales\Application\Api\CreateDealStageInput;
use Foresi\Sales\Application\StageWrites;
use Foresi\Shared\Infrastructure\Operation\OperationHandler;
use Foresi\Shared\Infrastructure\Operation\OperationOutput;

final readonly class CreateDealStageHandler implements OperationHandler
{
    public function __construct(private StageWrites $writes, private StageAnswer $answer)
    {
    }

    public static function input(): string
    {
        return CreateDealStageInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof CreateDealStageInput);

        return $this->answer->of($this->writes->create($input), 'Added the stage %s.');
    }
}
