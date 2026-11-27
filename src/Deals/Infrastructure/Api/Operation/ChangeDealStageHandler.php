<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Infrastructure\Api\Operation;

use Foresi\Deals\Application\Api\ChangeDealStageInput;
use Foresi\Deals\Application\DealWrites;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

final readonly class ChangeDealStageHandler implements OperationHandler
{
    public function __construct(private DealWrites $writes, private DealAnswer $answer)
    {
    }

    public static function input(): string
    {
        return ChangeDealStageInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof ChangeDealStageInput);

        return $this->answer->of($this->writes->changeStage($input)->id(), 'Moved the deal %s to another stage.');
    }
}
