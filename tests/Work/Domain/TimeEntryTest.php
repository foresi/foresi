<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Work\Domain;

use App\Identity\Domain\Membership;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Shared\Domain\Id;
use App\Tests\Party\Domain\PartyFactory;
use App\Work\Domain\Project;
use App\Work\Domain\ProjectStatus;
use App\Work\Domain\StatusType;
use App\Work\Domain\Task;
use App\Work\Domain\TimeEntry;
use App\Work\Domain\WorkflowTemplate;
use PHPUnit\Framework\TestCase;

final class TimeEntryTest extends TestCase
{
    private Project $project;

    private Task $task;

    private Membership $membership;

    protected function setUp(): void
    {
        $this->project = new Project('Website', null, WorkflowTemplate::Simple, Id::generate(), new \DateTimeImmutable('2026-11-10 08:00'));
        $this->task = new Task($this->project, 'Write the brief', new ProjectStatus($this->project, 'To do', StatusType::Open, 0), 0, Id::generate(), new \DateTimeImmutable('2026-11-10 08:00'));
        $this->membership = new Membership(new User('member@example.com', 'hash', 'Marek Member'), Role::Member);
    }

    public function testAProjectForAClientBillsTimeByDefaultAndAnInternalOneDoesNot(): void
    {
        $client = new Project('Shop', PartyFactory::person(), WorkflowTemplate::Simple, Id::generate(), new \DateTimeImmutable());
        self::assertTrue($client->billsTimeByDefault());
        self::assertFalse($this->project->billsTimeByDefault());

        // The default is set once; a client added later does not change it.
        $this->project->assignClient(PartyFactory::person());
        self::assertFalse($this->project->billsTimeByDefault());
        $this->project->billTimeByDefault(true);
        self::assertTrue($this->project->billsTimeByDefault());
    }

    public function testLoggedTimeKeepsTheDayItsMinutesAndANoteAndIsAuditedUnderItsTask(): void
    {
        $now = new \DateTimeImmutable('2026-11-10 17:00');
        $createdBy = Id::generate();
        $entry = TimeEntry::logged($this->project, $this->task, $this->membership, new \DateTimeImmutable('2026-11-09 15:30'), 90, null, null, '  Drafted it  ', true, $createdBy, $now);

        self::assertSame([$this->project, $this->task, $this->membership], [$entry->project(), $entry->task(), $entry->membership()]);
        self::assertSame(['2026-11-09 00:00', 90, 'Drafted it', true], [$entry->workedOn()->format('Y-m-d H:i'), $entry->minutes(), $entry->note(), $entry->isBillable()]);
        self::assertSame([null, null, false, false, null], [$entry->startedAt(), $entry->endedAt(), $entry->isRunning(), $entry->isInvoiced(), $entry->invoiceLineId()]);
        self::assertSame([$createdBy, $now], [$entry->createdBy(), $entry->createdAt()]);
        self::assertSame($this->task, $entry->auditRoot());
        self::assertTrue(Id::isValid($entry->id()));

        $onProject = TimeEntry::logged($this->project, null, $this->membership, $now, 30, null, null, '', false, $createdBy, $now);
        self::assertSame($this->project, $onProject->auditRoot());

        $this->expectException(\LogicException::class);
        $entry->tenantId();
    }

    public function testTimeIsAtLeastAMinuteWithBothAStartAndAnEndAfterItOrNeither(): void
    {
        $start = new \DateTimeImmutable('2026-11-10 09:00');
        $entry = TimeEntry::logged($this->project, null, $this->membership, $start, 60, $start, $start->modify('+1 hour'), '', false, Id::generate(), $start);
        self::assertSame(['09:00', '10:00'], [$entry->startedAt()?->format('H:i'), $entry->endedAt()?->format('H:i')]);

        foreach ([[0, null, null], [-5, null, null], [60, $start, null], [60, null, $start], [60, $start, $start], [60, $start, $start->modify('-1 hour')]] as [$minutes, $from, $to]) {
            try {
                TimeEntry::logged($this->project, null, $this->membership, $start, $minutes, $from, $to, '', false, Id::generate(), $start);
                self::fail('The time is refused.');
            } catch (\DomainException) {
            }
        }
    }

    public function testATaskOfAnotherProjectAndAnOverlongNoteAreRefused(): void
    {
        $other = new Project('Shop', null, WorkflowTemplate::Simple, Id::generate(), new \DateTimeImmutable());
        $foreign = new Task($other, 'Elsewhere', new ProjectStatus($other, 'To do', StatusType::Open, 0), 0, Id::generate(), new \DateTimeImmutable());
        $now = new \DateTimeImmutable('2026-11-10 09:00');

        foreach ([
            fn (): TimeEntry => TimeEntry::logged($this->project, $foreign, $this->membership, $now, 30, null, null, '', false, Id::generate(), $now),
            fn (): TimeEntry => TimeEntry::started($this->project, $foreign, $this->membership, $now, $now, '', false, Id::generate()),
            fn (): TimeEntry => TimeEntry::logged($this->project, null, $this->membership, $now, 30, null, null, str_repeat('a', TimeEntry::MAX_NOTE_LENGTH + 1), false, Id::generate(), $now),
        ] as $refused) {
            try {
                $refused();
                self::fail('The entry is refused.');
            } catch (\DomainException) {
            }
        }
        self::assertSame(TimeEntry::MAX_NOTE_LENGTH, mb_strlen(TimeEntry::checkedNote(str_repeat('é', TimeEntry::MAX_NOTE_LENGTH))));
    }

    public function testATimerRunsWithoutMinutesAndCountsAtLeastAMinuteWhenStopped(): void
    {
        $start = new \DateTimeImmutable('2026-11-10 09:00:00');
        $timer = TimeEntry::started($this->project, $this->task, $this->membership, $start, $start, 'Call', true, Id::generate());

        self::assertSame([true, null, $start, null], [$timer->isRunning(), $timer->minutes(), $timer->startedAt(), $timer->endedAt()]);
        self::assertSame($start, $timer->createdAt());

        $timer->stop($start->modify('+20 seconds'));
        self::assertSame([false, 1, '09:00:20'], [$timer->isRunning(), $timer->minutes(), $timer->endedAt()?->format('H:i:s')]);

        // Stopping again changes nothing.
        $timer->stop($start->modify('+2 hours'));
        self::assertSame([1, '09:00:20'], [$timer->minutes(), $timer->endedAt()?->format('H:i:s')]);

        $long = TimeEntry::started($this->project, null, $this->membership, $start, $start, '', true, Id::generate());
        $long->stop($start->modify('+1 hour 29 minutes 40 seconds'));
        self::assertSame(90, $long->minutes());
    }

    public function testAChangeRewritesTheEntryButNotWhileItRuns(): void
    {
        $now = new \DateTimeImmutable('2026-11-10 09:00');
        $timer = TimeEntry::started($this->project, $this->task, $this->membership, $now, $now, '', true, Id::generate());
        try {
            $timer->change(null, $now, 30, null, null, '', true);
            self::fail('A running timer is not changed.');
        } catch (\DomainException) {
        }
        $timer->stop($now->modify('+1 hour'));

        $timer->change(null, new \DateTimeImmutable('2026-11-11 14:00'), 45, null, null, ' Review ', false);
        self::assertSame([null, '2026-11-11', 45, null, null, 'Review', false], [$timer->task(), $timer->workedOn()->format('Y-m-d'), $timer->minutes(), $timer->startedAt(), $timer->endedAt(), $timer->note(), $timer->isBillable()]);
        self::assertSame($this->project, $timer->auditRoot());

        $timer->change($this->task, $now, 45, null, null, '', false);
        $timer->leaveTask();
        self::assertNull($timer->task());
    }
}
