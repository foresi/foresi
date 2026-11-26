<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Application;

use Foresi\Work\Application\ProjectOrder;
use Foresi\Work\Application\ProjectRow;
use Foresi\Work\Domain\ProjectVisibility;
use Foresi\Work\Domain\WorkflowTemplate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProjectOrderTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool, list<string>}>
     */
    public static function orders(): iterable
    {
        yield 'name' => [ProjectOrder::NAME, false, ['a', 'b', 'c', 'd']];
        yield 'name backwards' => [ProjectOrder::NAME, true, ['d', 'c', 'b', 'a']];
        yield 'open, ties by name' => [ProjectOrder::OPEN, false, ['d', 'a', 'c', 'b']];
        yield 'open backwards, ties by name backwards' => [ProjectOrder::OPEN, true, ['b', 'c', 'a', 'd']];
        yield 'overdue' => [ProjectOrder::OVERDUE, false, ['b', 'c', 'd', 'a']];
        yield 'overdue backwards' => [ProjectOrder::OVERDUE, true, ['a', 'd', 'c', 'b']];
        yield 'unassigned' => [ProjectOrder::UNASSIGNED, false, ['c', 'a', 'b', 'd']];
        yield 'unassigned backwards' => [ProjectOrder::UNASSIGNED, true, ['d', 'b', 'a', 'c']];
        yield 'due, no date last' => [ProjectOrder::DUE, false, ['c', 'a', 'd', 'b']];
        yield 'due backwards, no date still last' => [ProjectOrder::DUE, true, ['d', 'a', 'c', 'b']];
        yield 'unknown is by name' => ['client', false, ['a', 'b', 'c', 'd']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('orders')]
    public function testTheRowsAreOrderedWithTiesByName(string $sort, bool $descending, array $expected): void
    {
        $rows = [
            self::row('a', open: 2, overdue: 3, unassigned: 1, due: '2026-12-05'),
            self::row('b', open: 5, overdue: 0, unassigned: 1, due: null),
            self::row('c', open: 2, overdue: 1, unassigned: 0, due: '2026-12-01'),
            self::row('d', open: 0, overdue: 2, unassigned: 4, due: '2026-12-05'),
        ];

        self::assertSame($expected, array_map(static fn (ProjectRow $row): string => $row->id, ProjectOrder::sorted($rows, $sort, $descending)));
    }

    private static function row(string $name, int $open, int $overdue, int $unassigned, ?string $due): ProjectRow
    {
        return new ProjectRow($name, $name, null, null, WorkflowTemplate::Simple, $open, null === $due ? null : new \DateTimeImmutable($due), false, ProjectVisibility::Organisation, $overdue, $unassigned);
    }
}
