<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Calendar\Infrastructure\Api\Operation;

use Foresi\Calendar\Application\CreateFeedInput;
use Foresi\Calendar\Application\FeedWrites;
use Foresi\Calendar\Infrastructure\Api\CalendarJson;
use Foresi\Calendar\Infrastructure\Api\FeedAddresses;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

/**
 * The one answer that holds the address of a feed.
 */
final readonly class CreateFeedHandler implements OperationHandler
{
    public function __construct(private FeedWrites $writes, private FeedAddresses $addresses, private InstanceClock $clock)
    {
    }

    public static function input(): string
    {
        return CreateFeedInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof CreateFeedInput);
        $issued = $this->writes->create($input);

        return new OperationOutput(
            CalendarJson::feed($issued->feed, $this->clock->timezone()) + ['address' => $this->addresses->address($issued->token), 'webcal' => $this->addresses->webcal($issued->token)],
            \sprintf('Created the calendar feed %s. Its address is shown only now: subscribe to it in the calendar app.', $issued->feed->name()),
        );
    }
}
