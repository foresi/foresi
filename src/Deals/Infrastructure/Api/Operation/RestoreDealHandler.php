<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Infrastructure\Api\Operation;

use Foresi\Deals\Application\Api\RestoreDealInput;
use Foresi\Deals\Application\DealWrites;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

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
