<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Timesheet\Domain;

use Foresi\Timesheet\Domain\ProjectBudgetChange;
use Foresi\Work\Domain\Project;
use Foresi\Work\Domain\WorkflowTemplate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProjectBudgetChangeTest extends TestCase
{
    /**
     * @return iterable<string, array{int, string, bool}>
     */
    public static function changes(): iterable
    {
        yield 'a minute' => [1, '', true];
        yield 'a minus minute' => [-1, '', true];
        yield 'the most' => [ProjectBudgetChange::MAX_MINUTES, '', true];
        yield 'the most taken away' => [-ProjectBudgetChange::MAX_MINUTES, '', true];
        yield 'zero' => [0, '', false];
        yield 'one more than the most' => [ProjectBudgetChange::MAX_MINUTES + 1, '', false];
        yield 'one more taken away' => [-ProjectBudgetChange::MAX_MINUTES - 1, '', false];
        yield 'a note of 500 letters with accents' => [60, str_repeat('č', 500), true];
        yield 'a note of 501 letters' => [60, str_repeat('a', 501), false];
    }

    #[DataProvider('changes')]
    public function testTheMinutesAndTheNote(int $minutes, string $note, bool $valid): void
    {
        $project = new Project('DUD', null, WorkflowTemplate::Simple, '01900000-0000-7000-8000-000000000001', new \DateTimeImmutable('2026-11-25'));
        if (!$valid) {
            $this->expectException(\DomainException::class);
        }

        $change = new ProjectBudgetChange($project, new \DateTimeImmutable('2026-11-25'), $minutes, $note, null, 'user', new \DateTimeImmutable('2026-11-25 10:00'));

        self::assertSame([$minutes, $note, $project], [$change->minutes(), $change->note(), $change->project()]);
    }
}
