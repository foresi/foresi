<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Application;

use Doctrine\DBAL\Connection;
use Foresi\Shared\Domain\Id;
use Foresi\Tests\Work\Infrastructure\Api\WorkApiTestCase;
use Foresi\Work\Application\ManageTime;
use Foresi\Work\Application\ProjectBudget;
use Foresi\Work\Application\ProjectBudgetReads;
use Foresi\Work\Application\ProjectBudgets;
use Foresi\Work\Application\WorkRefused;
use Foresi\Work\Domain\BudgetCounting;
use Foresi\Work\Domain\Project;
use Foresi\Work\Domain\ProjectBudgetChange;

/**
 * A project's time budget (spec of the e-mail inbox, 4b–4d): the sum of its
 * dated changes, used by the project's time not in the trash, all of it or the
 * billable time only, and left as budget minus used, negative over budget,
 * which never blocks logging time.
 */
final class ProjectBudgetsTest extends WorkApiTestCase
{
    public function testTheDudCaseEightApprovedHoursThenTwoAndAHalfLogged(): void
    {
        $dud = $this->project('DUD');
        $this->as(fn (): ProjectBudgetChange => $this->budgets()->add($dud->id(), 480, note: 'Approved by e-mail'));

        $this->assertBudget([480, 0, 480], $dud);

        $this->logged($dud, null, 150);
        $this->assertBudget([480, 150, 330], $dud);
        self::assertEquals(['hours' => 8, 'used_hours' => 2.5, 'left_hours' => 5.5, 'counting' => 'all', 'estimate_hours' => 0.0], self::in($this->mcpTool('foresi_get_project', ['project_id' => $dud->id()]), 'structuredContent', 'data', 'budget'));
    }

    public function testAMinusChangeLowersTheBudgetButNeverBelowZero(): void
    {
        $project = $this->project();
        $this->as(fn (): ProjectBudgetChange => $this->budgets()->add($project->id(), 600));
        $this->as(fn (): ProjectBudgetChange => $this->budgets()->add($project->id(), -120, note: 'Two hours moved to the next phase'));
        $this->assertBudget([480, 0, 480], $project);

        self::assertSame('budget_below_zero', $this->refused(fn (): ProjectBudgetChange => $this->budgets()->add($project->id(), -481)));
        self::assertSame('hours_zero', $this->refused(fn (): ProjectBudgetChange => $this->budgets()->add($project->id(), 0)));
        self::assertSame('hours_invalid', $this->refused(fn (): ProjectBudgetChange => $this->budgets()->add($project->id(), 1_000_000)));
        $this->assertBudget([480, 0, 480], $project);
    }

    public function testRemovingAChangeThatHoldsUpAMinusIsRefused(): void
    {
        $project = $this->project();
        $plus = $this->as(fn (): string => $this->budgets()->add($project->id(), 600)->id());
        $this->as(fn (): ProjectBudgetChange => $this->budgets()->add($project->id(), -120));

        self::assertSame('budget_below_zero', $this->refused(fn () => $this->budgets()->remove($project->id(), $plus)));
        self::assertSame('unknown', $this->refused(fn () => $this->budgets()->remove($this->project('Other')->id(), $plus)));
    }

    public function testOverBudgetShowsANegativeLeftAndTimeIsStillLogged(): void
    {
        $project = $this->project();
        $this->as(fn (): ProjectBudgetChange => $this->budgets()->add($project->id(), 60));
        $this->logged($project, null, 90);
        $this->logged($project, null, 30);

        $budget = $this->budget($project);
        self::assertSame([60, 120, -60, true], [$budget->budgetMinutes, $budget->usedMinutes, $budget->leftMinutes(), $budget->isOver()]);
    }

    public function testTheSarahCaseCountsBillableOnly(): void
    {
        $sarah = $this->project('SARAH');
        $this->as(fn (): ProjectBudgetChange => $this->budgets()->add($sarah->id(), 600));
        $this->as(fn () => $this->budgets()->count($sarah->id(), BudgetCounting::Billable));
        $this->as(fn (): \Foresi\Work\Domain\TimeEntry => $this->time()->log($sarah->id(), null, new \DateTimeImmutable('2026-11-18'), 240, note: 'Flat-fee operation', billable: false));
        $this->as(fn (): \Foresi\Work\Domain\TimeEntry => $this->time()->log($sarah->id(), null, new \DateTimeImmutable('2026-11-18'), 90, note: 'Changes', billable: true));

        $this->assertBudget([600, 90, 510], $sarah);

        $this->as(fn () => $this->budgets()->count($sarah->id(), BudgetCounting::All));
        $this->assertBudget([600, 330, 270], $sarah);
    }

    public function testTrashedEntriesDoNotCount(): void
    {
        $project = $this->project();
        $this->as(fn (): ProjectBudgetChange => $this->budgets()->add($project->id(), 600));
        $task = $this->task($project, 'Homepage');
        $this->logged($project, null, 30);
        $trashed = $this->logged($project, null, 45);
        $this->as(fn (): \Foresi\Work\Domain\TimeEntry => $this->time()->delete($trashed));
        $this->logged($project, $task, 60);
        $this->as(fn (): \Foresi\Work\Domain\Task => $this->tasks()->delete($task));
        $this->as(fn (): \Foresi\Work\Domain\TimeEntry => $this->time()->startTimer($project->id(), null));

        $this->assertBudget([600, 30, 570], $project);
    }

    public function testAnArchivedProjectTakesNoChange(): void
    {
        $project = $this->project();
        $this->as(fn () => $this->projects()->archive($project->id()));

        self::assertSame('archived', $this->refused(fn (): ProjectBudgetChange => $this->budgets()->add($project->id(), 60)));
    }

    public function testTheChangesComeLatestFirstWithTheirAuthorAndTheTaskEstimates(): void
    {
        $project = $this->project();
        $this->as(fn (): ProjectBudgetChange => $this->budgets()->add($project->id(), 480, new \DateTimeImmutable('2026-11-01'), 'First phase'));
        $this->as(fn (): ProjectBudgetChange => $this->budgets()->add($project->id(), 120, new \DateTimeImmutable('2026-11-20'), 'Extra'));
        $task = $this->task($project, 'Design');
        $this->as(fn () => $this->time()->setEstimate($task, 90));

        $budget = $this->budget($project);
        self::assertSame(['Extra', 'First phase'], array_map(static fn (\Foresi\Work\Application\BudgetChangeRow $change): string => $change->note, $budget->changes ?? []));
        self::assertSame('2026-11-20', ($budget->changes ?? [])[0]->effectiveOn->format('Y-m-d'));
        self::assertNotNull(($budget->changes ?? [])[0]->authorName);
        self::assertSame(90, $budget->estimateMinutes);
    }

    public function testAGuestSeesTheBudgetButNotTheChanges(): void
    {
        $project = $this->project();
        $this->as(fn (): ProjectBudgetChange => $this->budgets()->add($project->id(), 480, note: 'Rate agreed with the client'));
        $guest = $this->guest();
        $this->as(fn () => $this->service(\Foresi\Work\Application\ManageSharing::class)->shareProject($project->id(), $this->membershipOf($guest)->id()));

        $budget = $this->as(fn (): ProjectBudget => $this->service(ProjectBudgetReads::class)->budget($project->id()), $guest);
        self::assertSame(480, $budget->budgetMinutes);
        self::assertNull($budget->changes);
        self::assertSame('not_allowed', $this->refused(fn (): ProjectBudgetChange => $this->budgets()->add($project->id(), 60), $guest));
    }

    public function testEachChangeRecordsAThinEvent(): void
    {
        $project = $this->project();
        $this->db()->executeStatement('DELETE FROM event_log');
        $change = $this->as(fn (): string => $this->budgets()->add($project->id(), 480, note: 'Secret terms')->id());
        $this->as(fn () => $this->budgets()->count($project->id(), BudgetCounting::Billable));
        $this->as(fn () => $this->budgets()->count($project->id(), BudgetCounting::Billable));
        $this->as(fn () => $this->budgets()->remove($project->id(), $change));

        $rows = $this->db()->fetchAllAssociative("SELECT name, data FROM event_log WHERE name LIKE 'work.%' ORDER BY id");
        self::assertSame(array_fill(0, 3, 'work.project_budget.changed'), array_column($rows, 'name'));
        self::assertSame([
            ['project_id' => $project->id(), 'change_id' => $change, 'change' => 'added'],
            ['project_id' => $project->id(), 'change_id' => null, 'change' => 'counting'],
            ['project_id' => $project->id(), 'change_id' => $change, 'change' => 'removed'],
        ], array_map(static fn (array $row): mixed => json_decode(\is_string($row['data']) ? $row['data'] : '', true, flags: \JSON_THROW_ON_ERROR), $rows));
    }

    public function testTheBudgetOverTheApi(): void
    {
        $project = $this->project();
        $this->send('POST', '/api/v1/projects/'.$project->id().'/budget-changes', ['hours' => '8', 'note' => 'Approved']);
        self::assertResponseStatusCodeSame(201);
        self::assertEquals(8, $this->at('data', 'hours'));
        $change = $this->at('data', 'changes', 0, 'id');
        self::assertIsString($change);

        $this->send('POST', '/api/v1/projects/'.$project->id().'/budget-changes', ['minutes' => -30, 'effective_on' => '2026-11-20']);
        self::assertEquals(7.5, $this->at('data', 'hours'));
        $this->send('POST', '/api/v1/projects/'.$project->id().'/budget-changes', ['hours' => '8', 'minutes' => 480]);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'hours', 'reason' => 'hours_invalid']);
        $this->send('POST', '/api/v1/projects/'.$project->id().'/budget-changes', ['hours' => '1.234']);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'hours', 'reason' => 'hours_invalid']);
        $this->send('POST', '/api/v1/projects/'.$project->id().'/budget-changes', ['hours' => '-9']);
        $this->assertProblem(409, 'budget_below_zero');

        $this->send('PUT', '/api/v1/projects/'.$project->id().'/budget-counting', ['counting' => 'billable']);
        self::assertSame('billable', $this->at('data', 'counting'));

        $this->send('DELETE', '/api/v1/projects/'.$project->id().'/budget-changes/'.$change);
        $this->assertProblem(409, 'budget_below_zero');

        $budget = $this->rest('/api/v1/projects/'.$project->id().'/budget');
        self::assertEquals(7.5, self::in($budget, 'hours'));
        self::assertCount(2, (array) self::in($budget, 'changes'));
        self::assertEquals(['hours' => 7.5, 'used_hours' => 0.0, 'left_hours' => 7.5, 'counting' => 'billable', 'estimate_hours' => 0.0], self::in($this->rest('/api/v1/projects/'.$project->id()), 'budget'));
        self::assertEquals(7.5, self::in($this->mcpTool('foresi_get_project_budget', ['project_id' => $project->id()]), 'structuredContent', 'data', 'hours'));
    }

    public function testTheLimitsOfAChangeAndOfItsNote(): void
    {
        $project = $this->project();
        $this->as(fn (): ProjectBudgetChange => $this->budgets()->add($project->id(), ProjectBudgetChange::MAX_MINUTES, note: '  '.str_repeat('č', 500).'  '));
        self::assertSame(str_repeat('č', 500), ($this->budget($project)->changes ?? [])[0]->note);

        self::assertSame('hours_invalid', $this->refused(fn (): ProjectBudgetChange => $this->budgets()->add($project->id(), -(ProjectBudgetChange::MAX_MINUTES + 1))));
        self::assertSame('budget_note', $this->refused(fn (): ProjectBudgetChange => $this->budgets()->add($project->id(), 60, note: str_repeat('a', 501))));
        $this->assertBudget([ProjectBudgetChange::MAX_MINUTES, 0, ProjectBudgetChange::MAX_MINUTES], $project);
    }

    public function testABudgetMayComeDownToZeroButAnEmptyOneTakesNoMinus(): void
    {
        $project = $this->project();
        self::assertSame('budget_below_zero', $this->refused(fn (): ProjectBudgetChange => $this->budgets()->add($project->id(), -1)));
        $this->as(fn (): ProjectBudgetChange => $this->budgets()->add($project->id(), 60));
        $minus = $this->as(fn (): string => $this->budgets()->add($project->id(), -60)->id());

        $budget = $this->budget($project);
        self::assertSame([0, false], [$budget->budgetMinutes, $budget->isOver()]);
        $this->as(fn () => $this->budgets()->remove($project->id(), $minus));
        $this->assertBudget([60, 0, 60], $project);
    }

    public function testTimeExactlyAtTheBudgetIsNotOver(): void
    {
        $project = $this->project();
        $this->as(fn (): ProjectBudgetChange => $this->budgets()->add($project->id(), 60));
        $this->logged($project, null, 60);

        self::assertFalse($this->budget($project)->isOver());
    }

    public function testAChangeIsRemovedOnlyFromItsOwnProject(): void
    {
        $project = $this->project();
        $other = $this->project('Other');
        $change = $this->as(fn (): string => $this->budgets()->add($project->id(), 60)->id());
        $this->as(fn (): ProjectBudgetChange => $this->budgets()->add($other->id(), 600));

        self::assertSame('unknown', $this->refused(fn () => $this->budgets()->remove($other->id(), $change)));
        self::assertSame('unknown', $this->refused(fn () => $this->budgets()->remove($project->id(), Id::generate())));
        $this->assertBudget([60, 0, 60], $project);
    }

    public function testNobodySignedInChangesNothing(): void
    {
        $project = $this->project();

        try {
            $this->budgets()->add($project->id(), 60);
            self::fail('Not refused.');
        } catch (WorkRefused $refused) {
            self::assertSame('not_allowed', $refused->reason);
        }
    }

    public function testTheDateOfAChangeOverTheApi(): void
    {
        $project = $this->project();
        $url = '/api/v1/projects/'.$project->id().'/budget-changes';
        foreach (['1899-12-31', '2026-02-30', 'not a date'] as $day) {
            $this->send('POST', $url, ['hours' => '1', 'effective_on' => $day]);
            $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'effective_on', 'reason' => 'date']);
        }
        $this->send('POST', $url, ['hours' => '1', 'effective_on' => '1900-01-01']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('1900-01-01', $this->at('data', 'changes', 0, 'effective_on'));
    }

    public function testAProjectWithoutABudgetHasNoneOverMcp(): void
    {
        $project = $this->project();

        self::assertNull(self::in($this->mcpTool('foresi_get_project', ['project_id' => $project->id()]), 'structuredContent', 'data', 'budget'));
    }

    /**
     * @param array{int, int, int} $expected budget, used and left minutes
     */
    private function assertBudget(array $expected, Project $project): void
    {
        $budget = $this->budget($project);
        self::assertSame($expected, [$budget->budgetMinutes, $budget->usedMinutes, $budget->leftMinutes()]);
    }

    private function budget(Project $project): ProjectBudget
    {
        return $this->as(fn (): ProjectBudget => $this->service(ProjectBudgetReads::class)->budget($project->id()));
    }

    /**
     * @param \Closure(): mixed $change
     */
    private function refused(\Closure $change, ?\Foresi\Identity\Domain\User $as = null): string
    {
        try {
            $this->as($change, $as);
        } catch (WorkRefused $refused) {
            return $refused->reason;
        }
        self::fail('Not refused.');
    }

    private function budgets(): ProjectBudgets
    {
        return $this->service(ProjectBudgets::class);
    }

    private function time(): ManageTime
    {
        return $this->service(ManageTime::class);
    }

    private function tasks(): \Foresi\Work\Application\ManageTasks
    {
        return $this->service(\Foresi\Work\Application\ManageTasks::class);
    }

    private function projects(): \Foresi\Work\Application\ManageProjects
    {
        return $this->service(\Foresi\Work\Application\ManageProjects::class);
    }

    private function db(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
