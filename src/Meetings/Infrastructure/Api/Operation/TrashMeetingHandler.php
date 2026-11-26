<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Infrastructure\Api\Operation;

use Foresi\Meetings\Application\Api\TrashMeetingInput;
use Foresi\Meetings\Application\MeetingWrites;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

final readonly class TrashMeetingHandler implements OperationHandler
{
    public function __construct(private MeetingWrites $writes, private MeetingAnswer $answer)
    {
    }

    public static function input(): string
    {
        return TrashMeetingInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof TrashMeetingInput);

        return $this->answer->meeting($this->writes->trash($input), 'Put the meeting %s in the trash; it is purged after 30 days unless restored.');
    }
}
