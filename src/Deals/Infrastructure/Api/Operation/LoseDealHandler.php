<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Infrastructure\Api\Operation;

use Foresi\Deals\Application\Api\LoseDealInput;
use Foresi\Deals\Application\DealWrites;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

final readonly class LoseDealHandler implements OperationHandler
{
    public function __construct(private DealWrites $writes, private DealAnswer $answer)
    {
    }

    public static function input(): string
    {
        return LoseDealInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof LoseDealInput);

        return $this->answer->of($this->writes->lose($input)->id(), 'Lost the deal %s.');
    }
}
