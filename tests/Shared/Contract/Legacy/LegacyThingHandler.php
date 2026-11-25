<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Contract\Legacy;

use Foresi\Shared\Contract\Operation\OperationOutput;
use Foresi\Shared\Infrastructure\Operation\OperationHandler;

/**
 * A handler written against the old name of OperationHandler. OperationOutput,
 * a final class, has no declaration under its old name that static analysis
 * could read, so it is used by its new one.
 */
final class LegacyThingHandler implements OperationHandler
{
    public static function input(): string
    {
        return LegacyThingInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        return new OperationOutput([], 'Changed.');
    }
}
