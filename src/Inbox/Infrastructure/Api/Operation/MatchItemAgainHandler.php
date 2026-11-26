<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Infrastructure\Api\Operation;

use Foresi\Inbox\Application\Api\MatchItemAgainInput;
use Foresi\Inbox\Application\ItemWrites;
use Foresi\Inbox\Infrastructure\Api\ItemAnswer;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

final readonly class MatchItemAgainHandler implements OperationHandler
{
    public function __construct(private ItemWrites $writes, private ItemAnswer $answer)
    {
    }

    public static function input(): string
    {
        return MatchItemAgainInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof MatchItemAgainInput);

        return $this->answer->of($this->writes->matchAgain($input), 'Matched the inbox item to its sender again.');
    }
}
