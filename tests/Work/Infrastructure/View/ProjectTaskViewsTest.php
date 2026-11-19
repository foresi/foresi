<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Infrastructure\View;

use Foresi\Shared\Application\View\Condition;
use Foresi\Work\Infrastructure\View\ProjectTaskViews;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The old filters of a project's list as the conditions they stand for (saved views D12).
 */
final class ProjectTaskViewsTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>, list<array{field: string, op: string, value?: mixed}>}>
     */
    public static function addresses(): iterable
    {
        yield 'none' => [[], []];
        yield 'me' => [['responsible' => 'me'], [['field' => 'responsible', 'op' => 'is', 'value' => 'me']]];
        yield 'a person' => [['responsible' => '01900000-0000-7000-8000-000000000001'], [['field' => 'responsible', 'op' => 'is', 'value' => '01900000-0000-7000-8000-000000000001']]];
        yield 'not a person' => [['responsible' => 'nobody'], []];
        yield 'an id with more after it' => [['responsible' => "01900000-0000-7000-8000-000000000001\nx"], []];
        yield 'more before an id' => [['responsible' => 'x01900000-0000-7000-8000-000000000001'], []];
        yield 'finished' => [['status' => 'finished'], [['field' => 'status', 'op' => 'is', 'value' => 'finished']]];
        yield 'every status' => [['status' => 'all'], [['field' => 'status', 'op' => 'is_any_of', 'value' => ['open', 'finished']]]];
        yield 'open was the default' => [['status' => 'open'], []];
        yield 'a due bucket' => [['due' => 'next_7_days'], [['field' => 'due', 'op' => 'is', 'value' => 'next_7_days']]];
        yield 'an unknown bucket' => [['due' => 'later'], []];
        yield 'a list is no value' => [['responsible' => ['me'], 'status' => ['all']], []];
        yield 'all three' => [['due' => 'today', 'status' => 'all', 'responsible' => 'me'], [
            ['field' => 'responsible', 'op' => 'is', 'value' => 'me'],
            ['field' => 'status', 'op' => 'is_any_of', 'value' => ['open', 'finished']],
            ['field' => 'due', 'op' => 'is', 'value' => 'today'],
        ]];
    }

    /**
     * @param array<string, string>                                 $query
     * @param list<array{field: string, op: string, value?: mixed}> $expected
     */
    #[DataProvider('addresses')]
    public function testAnOldParameterIsItsCondition(array $query, array $expected): void
    {
        self::assertSame($expected, array_map(static fn (Condition $condition): array => $condition->toArray(), ProjectTaskViews::conditions($query)));
    }
}
