<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Timesheet\Application;

use Foresi\Shared\Contract\RefusalKind;
use Foresi\Timesheet\Application\TimeRefused;
use PHPUnit\Framework\TestCase;

/**
 * How a refusal of time reads for the API and MCP: its code, its kind and its field.
 */
final class TimeRefusedTest extends TestCase
{
    public function testUnknownAndNotAllowedAnswerAsEveryModuleDoes(): void
    {
        self::assertSame(['not_found', RefusalKind::NotFound], [new TimeRefused('unknown')->reason(), new TimeRefused('unknown')->kind()]);
        self::assertSame(['forbidden', RefusalKind::Forbidden], [new TimeRefused('not_allowed')->reason(), new TimeRefused('not_allowed')->kind()]);
    }

    public function testTheOtherReasonsKeepTheirCodeAndHaveTheirKind(): void
    {
        self::assertSame(['time_not_yours', RefusalKind::Forbidden], [new TimeRefused('time_not_yours')->reason(), new TimeRefused('time_not_yours')->kind()]);
        self::assertSame(['timer_running', RefusalKind::Conflict], [new TimeRefused('timer_running')->reason(), new TimeRefused('timer_running')->kind()]);
        self::assertSame(['duration', RefusalKind::Invalid], [new TimeRefused('duration')->reason(), new TimeRefused('duration')->kind()]);
    }

    public function testTheMessageSaysWhatToDo(): void
    {
        self::assertSame('The timer is running; stop it first.', new TimeRefused('timer_running')->getMessage());
    }

    public function testTheFieldGivenWinsOverTheOneOfTheReason(): void
    {
        self::assertSame('minutes', new TimeRefused('duration')->field());
        self::assertSame('endedAt', new TimeRefused('duration', 'endedAt')->field());
        self::assertNull(new TimeRefused('archived')->field());
    }
}
