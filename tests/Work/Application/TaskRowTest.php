<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Application;

use Foresi\Work\Application\TaskRow;
use Foresi\Work\Domain\Priority;
use Foresi\Work\Domain\StatusType;
use PHPUnit\Framework\TestCase;

/**
 * A row of a task list: a task has no star unless the row says so (plan 37.2).
 */
final class TaskRowTest extends TestCase
{
    public function testARowIsNotStarredUnlessItSaysSo(): void
    {
        $row = new TaskRow('task', 'project', 'Website', null, null, 'Write the brief', 'status', 'To do', StatusType::Open, null, null, null, Priority::None, [], 0, false);
        $starred = new TaskRow('task', 'project', 'Website', null, null, 'Write the brief', 'status', 'To do', StatusType::Open, null, null, null, Priority::None, [], 0, false, starred: true);

        self::assertFalse($row->starred);
        self::assertTrue($starred->starred);
    }
}
