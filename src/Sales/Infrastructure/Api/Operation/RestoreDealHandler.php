<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Infrastructure\Api\Operation;

use Foresi\Sales\Application\Api\RestoreDealInput;
use Foresi\Sales\Application\DealWrites;
use Foresi\Shared\Infrastructure\Operation\OperationHandler;
use Foresi\Shared\Infrastructure\Operation\OperationOutput;

final readonly class RestoreDealHandler implements OperationHandler
{
    public function __construct(private DealWrites $writes, private DealAnswer $answer)
    {
    }

    public static function input(): string
    {
        return RestoreDealInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof RestoreDealInput);

        return $this->answer->of($this->writes->restore($input)->id(), 'Restored the deal %s from the trash.');
    }
}
