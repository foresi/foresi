<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Infrastructure\Api\Operation;

use Foresi\Meetings\Application\Api\UpdateMeetingInput;
use Foresi\Meetings\Application\MeetingWrites;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

final readonly class UpdateMeetingHandler implements OperationHandler
{
    public function __construct(private MeetingWrites $writes, private MeetingAnswer $answer)
    {
    }

    public static function input(): string
    {
        return UpdateMeetingInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof UpdateMeetingInput);

        return $this->answer->meeting($this->writes->update($input), 'Saved the meeting %s.');
    }
}
