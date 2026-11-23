<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Infrastructure\Api\Operation;

use Foresi\Sales\Application\Api\TrashDealInput;
use Foresi\Sales\Application\DealWrites;
use Foresi\Shared\Infrastructure\Operation\OperationHandler;
use Foresi\Shared\Infrastructure\Operation\OperationOutput;

final readonly class TrashDealHandler implements OperationHandler
{
    public function __construct(private DealWrites $writes, private DealAnswer $answer)
    {
    }

    public static function input(): string
    {
        return TrashDealInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof TrashDealInput);

        return $this->answer->of($this->writes->trash($input)->id(), 'Moved the deal %s to the trash.');
    }
}
