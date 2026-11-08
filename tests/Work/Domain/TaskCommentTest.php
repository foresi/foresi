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
use App\Work\Domain\CommentMention;
use App\Work\Domain\Project;
use App\Work\Domain\ProjectStatus;
use App\Work\Domain\StatusType;
use App\Work\Domain\Task;
use App\Work\Domain\TaskComment;
use App\Work\Domain\WorkflowTemplate;
use PHPUnit\Framework\TestCase;

final class TaskCommentTest extends TestCase
{
    private Task $task;

    private Membership $author;

    protected function setUp(): void
    {
        $project = new Project('Website', null, WorkflowTemplate::Simple, Id::generate(), new \DateTimeImmutable('2026-11-07 08:00'));
        $this->task = new Task($project, 'Task', new ProjectStatus($project, 'To do', StatusType::Open, 0), 0, Id::generate(), new \DateTimeImmutable('2026-11-07 08:00'));
        $this->author = new Membership(new User('testing@example.com', 'hash', 'Test Person'), Role::Member);
    }

    public function testANewCommentTrimsItsBodyAndIsAuditedUnderTheTask(): void
    {
        $comment = $this->comment("  Looks **good**\n");

        self::assertSame('Looks **good**', $comment->text());
        self::assertTrue($comment->isInternal());
        self::assertNull($comment->editedAt());
        self::assertFalse($comment->isDeleted());
        self::assertSame($this->task, $comment->auditRoot());
        self::assertSame($this->task, new CommentMention($comment, $this->author)->auditRoot());
        self::assertTrue(Id::isValid($comment->id()));
    }

    public function testABodyCannotBeEmptyOrTooLong(): void
    {
        foreach (["  \n ", str_repeat('é', TaskComment::MAX_BODY_LENGTH + 1)] as $body) {
            try {
                $this->comment($body);
                self::fail('The body is refused.');
            } catch (\DomainException) {
            }
        }

        self::assertSame(TaskComment::MAX_BODY_LENGTH, mb_strlen($this->comment(str_repeat('é', TaskComment::MAX_BODY_LENGTH))->text()));
    }

    public function testAnEditThatChangesNothingIsNotAnEdit(): void
    {
        $comment = $this->comment('First');
        $comment->edit(' First ', new \DateTimeImmutable('2026-11-07 09:00'));
        self::assertNull($comment->editedAt());

        $comment->edit('Second', new \DateTimeImmutable('2026-11-07 10:00'));
        self::assertSame(['Second', '2026-11-07 10:00'], [$comment->text(), $comment->editedAt()?->format('Y-m-d H:i')]);

        $this->expectException(\DomainException::class);
        $comment->edit('', new \DateTimeImmutable('2026-11-07 11:00'));
    }

    public function testDeletingKeepsTheCommentButClearsItsText(): void
    {
        $comment = $this->comment('Secret');
        $comment->delete(new \DateTimeImmutable('2026-11-07 09:00'));
        $comment->delete(new \DateTimeImmutable('2026-11-07 10:00'));

        self::assertTrue($comment->isDeleted());
        self::assertSame(['', '2026-11-07 09:00'], [$comment->text(), $comment->deletedAt()?->format('Y-m-d H:i')]);

        $this->expectException(\DomainException::class);
        $comment->edit('Again', new \DateTimeImmutable('2026-11-07 11:00'));
    }

    private function comment(string $body): TaskComment
    {
        return new TaskComment($this->task, $this->author, $body, true, new \DateTimeImmutable('2026-11-07 08:30'));
    }
}
