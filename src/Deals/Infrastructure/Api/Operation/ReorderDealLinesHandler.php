<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Infrastructure\Api\Operation;

use Foresi\Deals\Application\Api\ReorderDealLinesInput;
use Foresi\Deals\Application\DealLineWrites;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

final readonly class ReorderDealLinesHandler implements OperationHandler
{
    public function __construct(private DealLineWrites $writes, private DealAnswer $answer)
    {
    }

    public static function input(): string
    {
        return ReorderDealLinesInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof ReorderDealLinesInput);

        return $this->answer->of($this->writes->reorder($input)->id(), 'Put the lines of the deal %s in their new order.');
    }
}
