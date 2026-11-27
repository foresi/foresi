<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Infrastructure\Api\Operation;

use Foresi\Meetings\Application\ActionItemReads;
use Foresi\Meetings\Application\MeetingReads;
use Foresi\Meetings\Application\SeriesReads;
use Foresi\Meetings\Domain\Meeting;
use Foresi\Meetings\Domain\MeetingSeries;
use Foresi\Meetings\Infrastructure\Api\MeetingsApiController;
use Foresi\Meetings\Infrastructure\Api\MeetingsJson;
use Foresi\Shared\Contract\Operation\OperationOutput;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The answer of a write of Meetings: the series or the meeting, a summary and,
 * for a new one, where it is read.
 */
final readonly class MeetingAnswer
{
    public function __construct(private UrlGeneratorInterface $urls, private SeriesReads $series, private MeetingReads $meetings, private ActionItemReads $tasks)
    {
    }

    public function series(MeetingSeries $series, string $summary, bool $created = false): OperationOutput
    {
        return new OperationOutput(
            MeetingsJson::series($this->series->view($series->id())),
            \sprintf($summary, $series->name()),
            $created ? $this->urls->generate(MeetingsApiController::ONE_SERIES, ['seriesId' => $series->id()]) : null,
        );
    }

    public function meeting(Meeting $meeting, string $summary, bool $created = false): OperationOutput
    {
        return new OperationOutput(
            MeetingsJson::meeting($this->meetings->viewOf($meeting), $this->tasks->page($meeting, $meeting->minutes())),
            \sprintf($summary, MeetingsJson::summary($meeting)),
            $created ? $this->urls->generate(MeetingsApiController::MEETING, ['meetingId' => $meeting->id()]) : null,
        );
    }
}
