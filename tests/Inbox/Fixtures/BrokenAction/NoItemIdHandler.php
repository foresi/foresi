<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Inbox\Fixtures\BrokenAction;

use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

final readonly class NoItemIdHandler implements OperationHandler
{
    public static function input(): string
    {
        return NoItemIdInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        return new OperationOutput([], 'Nothing.');
    }
}
