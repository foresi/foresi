<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Application\View;

use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Work\Application\TaskRow;
use Foresi\Work\Application\View\TaskConditions;
use Foresi\Work\Domain\Priority;
use Foresi\Work\Domain\StatusType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Whether a task of a project's list holds the conditions of a view: finished tasks
 * only when a condition asks about the status, the responsible person ("me" too) and
 * the due buckets of the filters the list had.
 */
final class TaskConditionsTest extends TestCase
{
    private const string ME = '01900000-0000-7000-8000-000000000001';
    private const string OTHER = '01900000-0000-7000-8000-000000000002';

    /**
     * @return iterable<string, array{list<Condition>, list<string>}>
     */
    public static function filters(): iterable
    {
        yield 'no condition leaves out the finished' => [[], ['mine overdue', 'other today', 'nobody next week', 'nobody undated']];
        yield 'any status' => [[new Condition('status', 'is_any_of', ['open', 'finished'])], ['mine overdue', 'other today', 'nobody next week', 'nobody undated', 'mine past and done', 'other later and done']];
        yield 'finished' => [[new Condition('status', 'is', 'finished')], ['mine past and done', 'other later and done']];
        yield 'not finished' => [[new Condition('status', 'is_not', 'finished')], ['mine overdue', 'other today', 'nobody next week', 'nobody undated']];
        yield 'none of the statuses' => [[new Condition('status', 'is_none_of', ['open'])], ['mine past and done', 'other later and done']];
        yield 'me' => [[new Condition('responsible', 'is', 'me')], ['mine overdue']];
        yield 'a person' => [[new Condition('responsible', 'is', self::OTHER)], ['other today']];
        yield 'any of' => [[new Condition('responsible', 'is_any_of', ['me', self::OTHER])], ['mine overdue', 'other today']];
        yield 'not me' => [[new Condition('responsible', 'is_not', 'me')], ['other today', 'nobody next week', 'nobody undated']];
        yield 'nobody' => [[new Condition('responsible', 'is_empty')], ['nobody next week', 'nobody undated']];
        yield 'somebody' => [[new Condition('responsible', 'is_not_empty')], ['mine overdue', 'other today']];
        yield 'overdue' => [[new Condition('due', 'is', 'overdue')], ['mine overdue']];
        yield 'today' => [[new Condition('due', 'is', 'today')], ['other today']];
        yield 'the next seven days include today' => [[new Condition('due', 'is', 'next_7_days')], ['other today', 'nobody next week']];
        yield 'no date' => [[new Condition('due', 'is', 'none')], ['nobody undated']];
        yield 'not overdue' => [[new Condition('due', 'is_not', 'overdue')], ['other today', 'nobody next week', 'nobody undated']];
        yield 'a finished task past its date is not overdue' => [[new Condition('status', 'is', 'finished'), new Condition('due', 'is_none_of', ['overdue'])], ['mine past and done', 'other later and done']];
        yield 'all conditions hold' => [[new Condition('responsible', 'is_empty'), new Condition('due', 'is_any_of', ['next_7_days', 'overdue'])], ['nobody next week']];
        yield 'a title ignores case' => [[new Condition('title', 'contains', 'NOBODY')], ['nobody next week', 'nobody undated']];
        yield 'a title is not' => [[new Condition('title', 'is_not', 'Other Today')], ['mine overdue', 'nobody next week', 'nobody undated']];
        yield 'a title is never empty' => [[new Condition('title', 'is_empty')], []];
        yield 'a priority' => [[new Condition('priority', 'is_any_of', ['none'])], ['mine overdue', 'other today', 'nobody next week', 'nobody undated']];
        yield 'none of the priorities' => [[new Condition('priority', 'is_none_of', ['none'])], []];
        yield 'an unknown field narrows nothing' => [[new Condition('kind', 'is', 'x')], ['mine overdue', 'other today', 'nobody next week', 'nobody undated']];
    }

    /**
     * @param list<Condition> $conditions
     * @param list<string>    $titles
     */
    #[DataProvider('filters')]
    public function testItKeepsTheTasksThatHoldEveryCondition(array $conditions, array $titles): void
    {
        $conditions = new TaskConditions(ViewFilter::all(...$conditions), self::ME, new \DateTimeImmutable('2026-11-18'));

        self::assertSame($titles, array_values(array_map(static fn (TaskRow $task): string => $task->title, array_filter($this->tasks(), $conditions->matches(...)))));
    }

    public function testMeIsNobodyForSomeoneWithoutAMembership(): void
    {
        $conditions = new TaskConditions(ViewFilter::all(new Condition('responsible', 'is', 'me')), null, new \DateTimeImmutable('2026-11-18'));

        self::assertSame([], array_values(array_filter($this->tasks(), $conditions->matches(...))));
        $notMe = new TaskConditions(ViewFilter::all(new Condition('responsible', 'is_not', 'me')), null, new \DateTimeImmutable('2026-11-18'));
        self::assertCount(4, array_filter($this->tasks(), $notMe->matches(...)));
    }

    public function testAMonthEndCountsSevenDaysOn(): void
    {
        $conditions = new TaskConditions(ViewFilter::all(new Condition('due', 'is', 'next_7_days')), null, new \DateTimeImmutable('2026-11-27'));

        self::assertSame(['task on 2026-12-04'], array_values(array_map(static fn (TaskRow $task): string => $task->title, array_filter([$this->task('task on 2026-12-04', null, '2026-12-04', false), $this->task('task on 2026-12-05', null, '2026-12-05', false)], $conditions->matches(...)))));
    }

    /**
     * @return list<TaskRow>
     */
    private function tasks(): array
    {
        return [
            $this->task('mine overdue', self::ME, '2026-11-15', false),
            $this->task('other today', self::OTHER, '2026-11-18', false),
            $this->task('nobody next week', null, '2026-11-25', false),
            $this->task('nobody undated', null, null, false),
            // Finished, past its date: no longer overdue.
            $this->task('mine past and done', self::ME, '2026-11-10', true),
            $this->task('other later and done', self::OTHER, '2026-11-26', true),
        ];
    }

    private function task(string $title, ?string $responsible, ?string $due, bool $done): TaskRow
    {
        return new TaskRow(
            $title,
            'project',
            'Website',
            null,
            null,
            $title,
            'status',
            $done ? 'Done' : 'To do',
            $done ? StatusType::Done : StatusType::Open,
            $responsible,
            null === $responsible ? null : 'Someone',
            null === $due ? null : new \DateTimeImmutable($due),
            Priority::None,
            [],
            0,
            $done,
        );
    }
}
