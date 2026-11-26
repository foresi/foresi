<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Infrastructure\Api\Operation;

use Foresi\Meetings\Application\Api\SetMinutesInput;
use Foresi\Meetings\Application\MeetingWrites;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

final readonly class SetMinutesHandler implements OperationHandler
{
    public function __construct(private MeetingWrites $writes, private MeetingAnswer $answer)
    {
    }

    public static function input(): string
    {
        return SetMinutesInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof SetMinutesInput);

        return $this->answer->meeting($this->writes->setMinutes($input), 'Saved the minutes of %s.');
    }
}
