<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Application;

use Foresi\Work\Application\TaskSliceFilter;
use PHPUnit\Framework\TestCase;

/**
 * A slice of the tasks that asks for nothing narrows to nothing: it keeps the finished tasks
 * too, unlike the list work.tasks, and lists the newest first.
 */
final class TaskSliceFilterTest extends TestCase
{
    public function testAnEmptySliceNarrowsNothing(): void
    {
        $filter = new TaskSliceFilter();

        self::assertFalse($filter->open);
        self::assertSame('', $filter->text);
        self::assertNull($filter->projectId);
        self::assertNull($filter->responsible);
        self::assertNull($filter->statusType);
        self::assertNull($filter->finished);
        self::assertNull($filter->due);
        self::assertNull($filter->view);
        self::assertSame('', $filter->sort);
    }
}
