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
use App\Work\Domain\Notification;
use App\Work\Domain\NotificationKind;
use App\Work\Domain\Project;
use App\Work\Domain\ProjectStatus;
use App\Work\Domain\StatusType;
use App\Work\Domain\Task;
use App\Work\Domain\WorkflowTemplate;
use PHPUnit\Framework\TestCase;

final class NotificationTest extends TestCase
{
    private Membership $recipient;

    private Membership $olga;

    private Membership $marek;

    private Task $task;

    protected function setUp(): void
    {
        $this->recipient = $this->person('greta@example.com', 'Greta');
        $this->olga = $this->person('olga@example.com', 'Olga');
        $this->marek = $this->person('marek@example.com', 'Marek');
        $project = new Project('Website', null, WorkflowTemplate::Simple, Id::generate(), new \DateTimeImmutable('2026-11-07 08:00'));
        $this->task = new Task($project, 'Write the brief', new ProjectStatus($project, 'To do', StatusType::Open, 0), 0, Id::generate(), new \DateTimeImmutable('2026-11-07 08:00'));
    }

    public function testANewRowIsUnreadWithItsOneEvent(): void
    {
        $at = new \DateTimeImmutable('2026-11-10 09:00');
        $row = new Notification($this->recipient, $this->task, NotificationKind::Commented, $this->olga, $at);

        self::assertTrue(Id::isValid($row->id()));
        self::assertSame($this->recipient, $row->recipient());
        self::assertSame($this->task, $row->task());
        self::assertSame(NotificationKind::Commented, $row->kind());
        self::assertSame($this->olga, $row->actor());
        self::assertSame($at, $row->createdAt());
        self::assertSame(1, $row->events());
        self::assertFalse($row->isRead());
        self::assertNull($row->readAt());
        self::assertFalse($row->isDone());
        self::assertNull($row->doneAt());
        self::assertNull($row->emailedAt());
        self::assertFalse($row->hasDirectUnread());
        self::assertFalse($row->wasMentioned());
        self::assertFalse($row->wasAssigned());
    }

    public function testAMentionOrAnAssignmentIsDirect(): void
    {
        $mention = new Notification($this->recipient, $this->task, NotificationKind::Mentioned, $this->olga, new \DateTimeImmutable('2026-11-10 09:00'));
        $assignment = new Notification($this->recipient, $this->task, NotificationKind::Assigned, $this->olga, new \DateTimeImmutable('2026-11-10 09:00'));

        self::assertTrue($mention->hasDirectUnread());
        self::assertTrue($mention->wasMentioned());
        self::assertFalse($mention->wasAssigned());
        self::assertTrue($assignment->hasDirectUnread());
        self::assertTrue($assignment->wasAssigned());
        self::assertFalse($assignment->wasMentioned());
        self::assertSame(
            [true, true, false, false, false],
            array_map(static fn (NotificationKind $kind): bool => $kind->isDirect(), NotificationKind::cases()),
        );
    }

    public function testAnEventFoldsIntoTheUnreadRowAndKeepsItDirect(): void
    {
        $row = new Notification($this->recipient, $this->task, NotificationKind::Mentioned, $this->olga, new \DateTimeImmutable('2026-11-10 09:00'));
        $later = new \DateTimeImmutable('2026-11-10 10:00');
        $row->record(NotificationKind::StatusChanged, $this->marek, $later);

        self::assertSame(NotificationKind::StatusChanged, $row->kind());
        self::assertSame($this->marek, $row->actor());
        self::assertSame($later, $row->createdAt());
        self::assertSame(2, $row->events());
        self::assertTrue($row->hasDirectUnread());
        self::assertTrue($row->wasMentioned());

        $row->record(NotificationKind::Assigned, $this->olga, $later);
        self::assertSame(3, $row->events());
        self::assertTrue($row->wasAssigned());
    }

    public function testAnEventAfterReadingMakesTheRowUnreadWithOnlyTheNewEvent(): void
    {
        $row = new Notification($this->recipient, $this->task, NotificationKind::Mentioned, $this->olga, new \DateTimeImmutable('2026-11-10 09:00'));
        $row->record(NotificationKind::Commented, $this->olga, new \DateTimeImmutable('2026-11-10 09:30'));
        $read = new \DateTimeImmutable('2026-11-10 10:00');
        $row->markRead($read);
        $row->markRead(new \DateTimeImmutable('2026-11-10 11:00'));
        $row->markEmailed(new \DateTimeImmutable('2026-11-10 10:30'));

        self::assertTrue($row->isRead());
        self::assertSame($read, $row->readAt());
        self::assertFalse($row->hasDirectUnread());

        $row->record(NotificationKind::Commented, $this->marek, new \DateTimeImmutable('2026-11-10 12:00'));
        self::assertFalse($row->isRead());
        self::assertNull($row->readAt());
        self::assertNull($row->emailedAt());
        self::assertSame(1, $row->events());
        self::assertFalse($row->hasDirectUnread());
        self::assertTrue($row->wasMentioned());

        $row->markRead($read);
        $row->record(NotificationKind::Assigned, $this->marek, new \DateTimeImmutable('2026-11-10 13:00'));
        self::assertTrue($row->hasDirectUnread());
    }

    public function testDoneIsReadAndTakesNoMoreEvents(): void
    {
        $row = new Notification($this->recipient, $this->task, NotificationKind::Shared, $this->olga, new \DateTimeImmutable('2026-11-10 09:00'));
        $done = new \DateTimeImmutable('2026-11-10 10:00');
        $row->markDone($done);
        $row->markDone(new \DateTimeImmutable('2026-11-10 11:00'));

        self::assertTrue($row->isDone());
        self::assertSame($done, $row->doneAt());
        self::assertSame($done, $row->readAt());
        try {
            $row->record(NotificationKind::Commented, $this->olga, new \DateTimeImmutable('2026-11-10 12:00'));
            self::fail('A done row takes no event.');
        } catch (\DomainException) {
        }
        self::assertSame(NotificationKind::Shared, $row->kind());

        $row->markUndone();
        self::assertFalse($row->isDone());
        self::assertNull($row->doneAt());
        self::assertTrue($row->isRead());
    }

    public function testTheTenantIsStampedOnPersist(): void
    {
        $this->expectException(\LogicException::class);
        new Notification($this->recipient, $this->task, NotificationKind::Shared, $this->olga, new \DateTimeImmutable())->tenantId();
    }

    private function person(string $email, string $name): Membership
    {
        return new Membership(new User($email, 'hash', $name), Role::Member);
    }
}
