<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Events\Infrastructure\Relay;

use App\Tenancy\Application\Schedule\OperatorTask;

/**
 * Sweeps the log on every tick of the schedule, for rows no nudge announced: a
 * commit made outside the unit of work, a nudge that could not be sent or one
 * that failed. Usually there is nothing to do.
 */
final readonly class RelayEventsTask implements OperatorTask
{
    public const string NAME = 'event-relay';

    public function __construct(private EventRelay $relay)
    {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function every(): string
    {
        return '5 minutes';
    }

    public function run(): void
    {
        $this->relay->relayAll();
    }
}
