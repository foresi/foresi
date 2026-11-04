<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Invoicing\Domain\RecurrenceEnd;
use App\Invoicing\Domain\RecurringInvoice;
use App\Invoicing\Domain\RecurringState;
use App\Party\Application\TransactionalWork;

final class RecurringLifecycleTest extends RecurringTestCase
{
    public function testPauseResumeSkipsMissedDates(): void
    {
        $template = $this->save->create($this->recurringInput());
        $this->lifecycle->pause($template->id());
        $paused = $this->templateRow($template->id());
        self::assertSame('paused', $paused['state']);
        self::assertNotNull($paused['paused_at']);
        self::assertSame([], $this->templates->due(new \DateTimeImmutable('2027-01-20')), 'a paused template is never due');

        self::mockTime('2027-01-20 11:00:00');
        $skipped = $this->lifecycle->resume($template->id(), new \DateTimeImmutable('2027-01-20'));

        self::assertSame(['2026-12-01', '2027-01-01'], array_map(static fn (\DateTimeImmutable $date): string => $date->format('Y-m-d'), $skipped));
        $row = $this->templateRow($template->id());
        self::assertSame('active', $row['state']);
        self::assertNull($row['paused_at']);
        self::assertSame('2027-02-01', $row['next_occurrence']);
        self::assertSame(0, $row['issued_count']);
    }

    public function testResumeOnTheDayOfAnOccurrenceKeepsIt(): void
    {
        $template = $this->save->create($this->recurringInput());
        $this->lifecycle->pause($template->id());

        self::assertSame([], $this->lifecycle->resume($template->id(), new \DateTimeImmutable('2026-12-01')));
        self::assertSame('2026-12-01', $this->templateRow($template->id())['next_occurrence']);
    }

    public function testSkippedDatesDoNotCountTowardsTheEnd(): void
    {
        $template = $this->save->create($this->recurringInput(overrides: ['recurrence' => $this->monthly(end: RecurrenceEnd::AfterCount, endAfter: 2)]));
        $this->lifecycle->pause($template->id());

        $this->lifecycle->resume($template->id(), new \DateTimeImmutable('2027-01-20'));

        self::assertSame('2027-02-01', $this->templateRow($template->id())['next_occurrence'], 'two invoices are still to come');
    }

    public function testResumePastTheEndDateEndsTheTemplate(): void
    {
        $template = $this->save->create($this->recurringInput(overrides: ['recurrence' => $this->monthly(end: RecurrenceEnd::OnDate, endOn: '2027-01-15')]));
        $this->lifecycle->pause($template->id());

        $skipped = $this->lifecycle->resume($template->id(), new \DateTimeImmutable('2027-03-01'));

        self::assertCount(2, $skipped);
        $row = $this->templateRow($template->id());
        self::assertSame('ended', $row['state']);
        self::assertNull($row['next_occurrence']);
        self::assertNotNull($row['ended_at']);
    }

    public function testEndIsFinal(): void
    {
        $template = $this->save->create($this->recurringInput());
        $this->lifecycle->end($template->id());

        $row = $this->templateRow($template->id());
        self::assertSame('ended', $row['state']);
        self::assertNotNull($row['ended_at']);
        self::assertNull($row['next_occurrence']);
        self::assertSame([], $this->templates->due(new \DateTimeImmutable('2027-12-31')));

        self::assertSame(['recurring.problem.ended'], $this->recurringRefusalOf(fn (): array => $this->lifecycle->resume($template->id(), new \DateTimeImmutable('2026-12-01'))));
        self::assertSame(['recurring.problem.ended'], $this->recurringRefusalOf(fn () => $this->lifecycle->pause($template->id())));
        self::assertSame(['recurring.problem.ended'], $this->recurringRefusalOf(fn (): RecurringInvoice => $this->save->edit($template->id(), $this->templates->get($template->id())->version(), $this->recurringInput())));
        self::assertSame('ended', $this->templateRow($template->id())['state']);
    }

    public function testTheEndRuleEndsTheTemplateAfterItsLastOccurrence(): void
    {
        $template = $this->save->create($this->recurringInput(overrides: ['recurrence' => $this->monthly(end: RecurrenceEnd::AfterCount, endAfter: 1)]));

        $this->advance($template->id(), '2026-12-01');

        $row = $this->templateRow($template->id());
        self::assertSame('ended', $row['state']);
        self::assertSame(1, $row['issued_count']);
        self::assertNull($row['next_occurrence']);
        self::assertSame('2026-12-01', $row['last_occurrence']);
    }

    public function testAdvanceTakesOnlyTheNextOccurrenceOfAnActiveTemplate(): void
    {
        $template = $this->save->create($this->recurringInput());

        $this->expectException(\DomainException::class);
        $this->advance($template->id(), '2027-01-01');
    }

    public function testDeleteOnlyBeforeTheFirstIssue(): void
    {
        $unused = $this->save->create($this->recurringInput([$this->line(text: 'One'), $this->line(text: 'Two')]));
        $issued = $this->save->create($this->recurringInput());
        $this->advance($issued->id(), '2026-12-01');

        $this->lifecycle->delete($unused->id());

        $connection = $this->em->getConnection();
        self::assertFalse($connection->fetchOne('SELECT id FROM recurring_invoice WHERE id = :id', ['id' => $unused->id()]));
        self::assertSame([], $this->lineRows($unused->id()));

        $this->em->clear();
        self::assertSame(['recurring.problem.issued'], $this->recurringRefusalOf(fn () => $this->lifecycle->delete($issued->id())));
        self::assertSame('active', $this->templateRow($issued->id())['state']);
        self::assertCount(1, $this->lineRows($issued->id()));
    }

    public function testDueListsActiveTemplatesUpToToday(): void
    {
        $today = $this->save->create($this->recurringInput(overrides: ['recurrence' => $this->monthly('2026-11-02')]));
        $later = $this->save->create($this->recurringInput(overrides: ['recurrence' => $this->monthly('2026-11-03')]));
        $paused = $this->save->create($this->recurringInput(overrides: ['recurrence' => $this->monthly('2026-11-02')]), startPaused: true);

        self::assertSame([$today->id()], $this->templates->due(new \DateTimeImmutable('2026-11-02')));
        $both = [$today->id(), $later->id()];
        sort($both);
        self::assertSame($both, $this->templates->due(new \DateTimeImmutable('2026-11-03')), 'in id order');
        self::assertNotContains($paused->id(), $this->templates->due(new \DateTimeImmutable('2026-12-31')));
    }

    public function testAFailureIsRecordedAndCleared(): void
    {
        $template = $this->save->create($this->recurringInput());
        $work = self::getContainer()->get(TransactionalWork::class);
        $work->run(function () use ($template): void {
            $locked = $this->templates->lock($template->id());
            $locked->recordFailure(new \DateTimeImmutable('2026-12-01 05:00:00'), 'customer_archived');
            $locked->markAlerted(new \DateTimeImmutable('2026-12-01'));
        });

        $row = $this->templateRow($template->id());
        self::assertSame('customer_archived', $row['last_failure_code']);
        self::assertNotNull($row['last_failure_at']);
        self::assertSame('2026-12-01', $row['last_alert_on']);
        self::assertSame('2026-12-01', $row['next_occurrence'], 'the next run tries again');
        self::assertSame(1, $this->templates->failing());

        $work->run(function () use ($template): void {
            $this->templates->lock($template->id())->clearFailure();
        });
        $row = $this->templateRow($template->id());
        self::assertNull($row['last_failure_code']);
        self::assertNull($row['last_failure_at']);
        self::assertSame(0, $this->templates->failing());
        self::assertSame(RecurringState::Active, $this->templates->get($template->id())->state());
    }

    private function advance(string $id, string $occurrence): void
    {
        self::getContainer()->get(TransactionalWork::class)->run(function () use ($id, $occurrence): void {
            $this->templates->lock($id)->advance(new \DateTimeImmutable($occurrence));
        });
    }
}
