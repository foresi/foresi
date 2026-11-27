<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Infrastructure\Api\Operation;

use Foresi\Deals\Application\Api\RemoveDealLineInput;
use Foresi\Deals\Application\DealLineWrites;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

final readonly class RemoveDealLineHandler implements OperationHandler
{
    public function __construct(private DealLineWrites $writes, private DealAnswer $answer)
    {
    }

    public static function input(): string
    {
        return RemoveDealLineInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof RemoveDealLineInput);

        return $this->answer->of($this->writes->remove($input)->id(), 'Removed a line of the deal %s.');
    }
}
