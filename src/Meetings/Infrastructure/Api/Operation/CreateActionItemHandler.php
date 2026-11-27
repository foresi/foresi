<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Infrastructure\Api\Operation;

use Foresi\Meetings\Application\ActionItemWrites;
use Foresi\Meetings\Application\Api\CreateActionItemInput;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

final readonly class CreateActionItemHandler implements OperationHandler
{
    public function __construct(private ActionItemWrites $writes, private MeetingAnswer $answer)
    {
    }

    public static function input(): string
    {
        return CreateActionItemInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof CreateActionItemInput);
        [$item, $meeting] = $this->writes->create($input);

        return $this->answer->meeting($meeting, 'Created the task '.$item->taskId().' as an action item of %s.');
    }
}
