<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Calendar\Infrastructure\Api\Operation;

use Foresi\Calendar\Application\FeedWrites;
use Foresi\Calendar\Application\RevokeFeedInput;
use Foresi\Calendar\Infrastructure\Api\CalendarJson;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

final readonly class RevokeFeedHandler implements OperationHandler
{
    public function __construct(private FeedWrites $writes, private InstanceClock $clock)
    {
    }

    public static function input(): string
    {
        return RevokeFeedInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof RevokeFeedInput);
        $feed = $this->writes->revoke($input);

        return new OperationOutput(CalendarJson::feed($feed, $this->clock->timezone()), \sprintf('Revoked the calendar feed %s.', $feed->name()));
    }
}
