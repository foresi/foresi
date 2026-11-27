<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Infrastructure\Doctrine;

use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Work\Application\View\ProjectLists;
use Foresi\Work\Infrastructure\Doctrine\ProjectTaskCounts;
use PHPUnit\Framework\TestCase;

final class ProjectTaskCountsTest extends TestCase
{
    public function testAFilterSplitsIntoTheProjectsOwnConditionsAndThoseOnItsCountsInTheirOrder(): void
    {
        $visibility = new Condition(ProjectLists::VISIBILITY, 'is', 'organisation');
        $overdue = new Condition(ProjectLists::OVERDUE_TASKS, 'gt', 0);
        $client = new Condition(ProjectLists::CLIENT, 'is_empty');
        $open = new Condition(ProjectLists::OPEN_TASKS, 'ge', 2);
        $unassigned = new Condition(ProjectLists::UNASSIGNED_TASKS, 'eq', 0);
        $due = new Condition(ProjectLists::NEXT_DUE, 'is_empty');

        [$own, $counts] = ProjectTaskCounts::split(new ViewFilter([$visibility, $overdue, $client, $open, $unassigned, $due]));

        self::assertSame([$visibility, $client], $own->conditions);
        self::assertSame([$overdue, $open, $unassigned, $due], $counts->conditions);
    }

    public function testAFilterWithNoCountKeepsItsConditionsAndCountsNothing(): void
    {
        $visibility = new Condition(ProjectLists::VISIBILITY, 'is', 'organisation');

        [$own, $counts] = ProjectTaskCounts::split(new ViewFilter([$visibility]));

        self::assertSame([$visibility], $own->conditions);
        self::assertSame([], $counts->conditions);
    }
}
