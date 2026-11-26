<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Infrastructure\Api\Operation;

use Foresi\Meetings\Application\Api\CreateSeriesInput;
use Foresi\Meetings\Application\SeriesWrites;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

final readonly class CreateSeriesHandler implements OperationHandler
{
    public function __construct(private SeriesWrites $writes, private MeetingAnswer $answer)
    {
    }

    public static function input(): string
    {
        return CreateSeriesInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof CreateSeriesInput);

        return $this->answer->series($this->writes->create($input), 'Created the meeting series %s.', true);
    }
}
