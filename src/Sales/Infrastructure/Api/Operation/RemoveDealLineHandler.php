<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Infrastructure\Api\Operation;

use Foresi\Sales\Application\Api\RemoveDealLineInput;
use Foresi\Sales\Application\DealLineWrites;
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
