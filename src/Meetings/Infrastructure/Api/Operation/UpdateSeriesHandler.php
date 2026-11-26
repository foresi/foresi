<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Infrastructure\Api\Operation;

use Foresi\Meetings\Application\Api\UpdateSeriesInput;
use Foresi\Meetings\Application\SeriesWrites;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

final readonly class UpdateSeriesHandler implements OperationHandler
{
    public function __construct(private SeriesWrites $writes, private MeetingAnswer $answer)
    {
    }

    public static function input(): string
    {
        return UpdateSeriesInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof UpdateSeriesInput);

        return $this->answer->series($this->writes->update($input), 'Saved the meeting series %s.');
    }
}
