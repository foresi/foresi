<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Infrastructure\Api\Operation;

use Foresi\Deals\Application\Api\ReorderDealStagesInput;
use Foresi\Deals\Application\StageWrites;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

final readonly class ReorderDealStagesHandler implements OperationHandler
{
    public function __construct(private StageWrites $writes, private StageAnswer $answer)
    {
    }

    public static function input(): string
    {
        return ReorderDealStagesInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof ReorderDealStagesInput);

        return $this->answer->all($this->writes->reorder($input), 'Put the stages in their new order.');
    }
}
