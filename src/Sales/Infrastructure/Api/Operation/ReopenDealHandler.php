<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Infrastructure\Api\Operation;

use Foresi\Sales\Application\Api\ReopenDealInput;
use Foresi\Sales\Application\DealWrites;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

final readonly class ReopenDealHandler implements OperationHandler
{
    public function __construct(private DealWrites $writes, private DealAnswer $answer)
    {
    }

    public static function input(): string
    {
        return ReopenDealInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof ReopenDealInput);

        return $this->answer->of($this->writes->reopen($input)->id(), 'Reopened the deal %s.');
    }
}
