<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Infrastructure\Operation;

use Foresi\Shared\Infrastructure\Operation\OperationHandler;
use Foresi\Shared\Infrastructure\Operation\OperationOutput;

/**
 * The handler of MappingThingInput, for its definition only.
 */
final class MappingThingHandler implements OperationHandler
{
    public static function input(): string
    {
        return MappingThingInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        return new OperationOutput(['ok' => true], 'Mapped.');
    }
}
