<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Infrastructure\Operation;

use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

final class ConfirmedThingHandler implements OperationHandler
{
    public bool $ran = false;

    public static function input(): string
    {
        return ConfirmedThingInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        $this->ran = true;

        return new OperationOutput([], 'Issued.');
    }
}
