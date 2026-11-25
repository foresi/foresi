<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Infrastructure\Api\Operation;

use Foresi\Sales\Application\Api\UpdateDealStageInput;
use Foresi\Sales\Application\StageWrites;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

final readonly class UpdateDealStageHandler implements OperationHandler
{
    public function __construct(private StageWrites $writes, private StageAnswer $answer)
    {
    }

    public static function input(): string
    {
        return UpdateDealStageInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof UpdateDealStageInput);

        return $this->answer->of($this->writes->update($input), 'Renamed the stage %s.');
    }
}
