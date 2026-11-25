<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Infrastructure\Api\Operation;

use Foresi\Inbox\Application\Api\RestoreItemInput;
use Foresi\Inbox\Application\ItemWrites;
use Foresi\Inbox\Infrastructure\Api\ItemAnswer;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

final readonly class RestoreItemHandler implements OperationHandler
{
    public function __construct(private ItemWrites $writes, private ItemAnswer $answer)
    {
    }

    public static function input(): string
    {
        return RestoreItemInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof RestoreItemInput);

        return $this->answer->of($this->writes->restore($input), 'Restored the inbox item.');
    }
}
