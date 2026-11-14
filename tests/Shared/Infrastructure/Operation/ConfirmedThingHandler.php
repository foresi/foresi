<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Operation;

use App\Shared\Infrastructure\Operation\OperationHandler;
use App\Shared\Infrastructure\Operation\OperationOutput;

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
