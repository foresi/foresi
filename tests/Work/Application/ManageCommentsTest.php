<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Work\Application;

use App\Attachment\Application\AttachmentPermissions;
use App\Attachment\Domain\Attachment;
use App\Shared\Domain\Id;
use App\Work\Application\DiscussionFilter;
use App\Work\Application\DiscussionItem;
use App\Work\Application\ManageComments;
use App\Work\Application\MentionCandidate;
use App\Work\Application\TaskHistory;
use App\Work\Application\TaskHistoryEntry;
use App\Work\Application\WorkRefused;
use App\Work\Domain\CommentMention;
use App\Work\Domain\Task;
use App\Work\Domain\TaskComment;
use App\Work\Domain\TaskWatcher;

final class ManageCommentsTest extends WorkTestCase
{
    private ManageComments $comments;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        $this->comments = self::getContainer()->get(ManageComments::class);
        $this->task = $this->tasks->create($this->project()->id(), 'Write the brief');
    }

    public function testANewCommentIsInternalByDefaultAndItsAuthorFollowsTheTask(): void
    {
        $this->signIn($this->member);
        $comment = $this->comments->add($this->task->id(), '  Started on it  ');
        $public = $this->comments->add($this->task->id(), 'For the client too', false);
        $this->comments->add($this->task->id(), 'Once more');

        self::assertSame('Started on it', $comment->text());
        self::assertTrue($comment->isInternal());
        self::assertFalse($public->isInternal());
        self::assertSame(['Marek Member', 'Olga Owner'], $this->watchers());
    }

    public function testABodyMustSaySomethingAndNotTooMuch(): void
    {
        $this->assertRefused('comment_empty', fn (): TaskComment => $this->comments->add($this->task->id(), "  \n"));
        $this->assertRefused('comment_too_long', fn (): TaskComment => $this->comments->add($this->task->id(), str_repeat('a', TaskComment::MAX_BODY_LENGTH + 1)));
        $comment = $this->comments->add($this->task->id(), 'Fine');
        $this->assertRefused('comment_empty', fn () => $this->comments->edit($comment->id(), ' '));
    }

    public function testABodyOfExactlyTheLimitInCharactersIsKept(): void
    {
        $comment = $this->comments->add($this->task->id(), str_repeat('é', TaskComment::MAX_BODY_LENGTH));
        self::assertSame(TaskComment::MAX_BODY_LENGTH, mb_strlen($comment->text()));

        $this->comments->edit($comment->id(), str_repeat('ž', TaskComment::MAX_BODY_LENGTH));
        self::assertSame(str_repeat('ž', TaskComment::MAX_BODY_LENGTH), $comment->text());
        $this->assertRefused('comment_too_long', fn () => $this->comments->edit($comment->id(), str_repeat('ž', TaskComment::MAX_BODY_LENGTH + 1)));
    }

    public function testOnlyStaffCommentsOnTasksTheySee(): void
    {
        $this->assertRefused('unknown', fn (): TaskComment => $this->comments->add(Id::generate(), 'Hello'));
        $comment = $this->comments->add($this->task->id(), 'Hello');

        $this->signIn($this->guest);
        $this->assertRefused('unknown', fn (): TaskComment => $this->comments->add($this->task->id(), 'Hello'));
        $this->assertRefused('unknown', fn () => $this->comments->delete($comment->id()));

        $this->member->deactivate($this->clock->now());
        $this->em->flush();
        $this->signIn($this->member);
        $this->assertRefused('unknown', fn (): TaskComment => $this->comments->add($this->task->id(), 'Hello'));
    }

    public function testOnlyTheAuthorEditsAndAnOwnerMayAlsoDelete(): void
    {
        $this->signIn($this->member);
        $mine = $this->comments->add($this->task->id(), 'Mine');
        $this->clock->sleep(60);
        $this->comments->edit($mine->id(), 'Mine, edited');
        self::assertSame(['Mine, edited', '2026-11-10 09:01'], [$mine->text(), $mine->editedAt()?->format('Y-m-d H:i')]);

        $this->signIn($this->owner);
        $theirs = $this->comments->add($this->task->id(), 'The owner speaks');
        $this->assertRefused('comment_not_yours', fn () => $this->comments->edit($mine->id(), 'Words in their mouth'));

        $this->signIn($this->member);
        $this->assertRefused('comment_not_yours', fn () => $this->comments->edit($theirs->id(), 'No'));
        $this->assertRefused('comment_not_yours', fn () => $this->comments->delete($theirs->id()));
        $this->comments->delete($mine->id());
        self::assertSame([true, ''], [$mine->isDeleted(), $mine->text()]);
        $this->assertRefused('comment_deleted', fn () => $this->comments->edit($mine->id(), 'Back'));

        // An owner moderates.
        $other = $this->comments->add($this->task->id(), 'Off topic');
        $this->signIn($this->owner);
        $this->comments->delete($other->id());
        self::assertTrue($other->isDeleted());
    }

    public function testMentionsFollowTheTextAndOnlyActiveStaffWhoSeeTheTask(): void
    {
        // A guest does not see the task; an unknown id means nobody.
        $comment = $this->comments->add($this->task->id(), sprintf(
            'Hi %s and %s, also %s and %s',
            $this->mention('Marek Member', $this->member->id()),
            $this->mention('Greta Guest', $this->guest->id()),
            $this->mention('Nobody', Id::generate()),
            $this->mention('Marek again', strtoupper($this->member->id())),
        ));
        self::assertSame(['Marek Member'], $this->mentioned($comment));

        $this->comments->edit($comment->id(), sprintf('Over to %s', $this->mention('Olga', $this->owner->id())));
        self::assertSame(['Olga Owner'], $this->mentioned($comment));

        $this->member->deactivate($this->clock->now());
        $this->em->flush();
        $this->comments->edit($comment->id(), sprintf('%s and %s', $this->mention('Olga', $this->owner->id()), $this->mention('Marek', $this->member->id())));
        self::assertSame(['Olga Owner'], $this->mentioned($comment));

        $this->comments->delete($comment->id());
        self::assertSame([], $this->mentioned($comment));
    }

    public function testTheDiscussionMixesCommentsAndHistoryOldestFirst(): void
    {
        $this->clock->sleep(60);
        $this->signIn($this->member);
        $first = $this->comments->add($this->task->id(), 'Hello **team**');
        $this->clock->sleep(60);
        $this->signIn($this->owner);
        $this->tasks->rename($this->task->id(), 'Write the short brief');
        $this->clock->sleep(60);
        $second = $this->comments->add($this->task->id(), 'Gone soon');
        $this->comments->edit($second->id(), 'Gone soon, edited');
        $this->comments->delete($second->id());

        $all = $this->queries->discussion($this->task->id());
        self::assertNotNull($all);
        self::assertSame(['step:created', 'comment:'.$first->id(), 'step:changed', 'comment:'.$second->id()], array_map($this->describe(...), $all));
        $hello = $all[1]->comment;
        self::assertNotNull($hello);
        self::assertSame(['Marek Member', 'MM', true, false, false, true], [$hello->authorName, $hello->authorInitials, $hello->internal, $hello->deleted, $hello->canEdit, $hello->canDelete]);
        self::assertStringContainsString('<strong>team</strong>', $hello->html);
        $gone = $all[3]->comment;
        self::assertNotNull($gone);
        self::assertSame([true, '', false, false], [$gone->deleted, $gone->html, $gone->canEdit, $gone->canDelete]);

        self::assertSame(['comment:'.$first->id(), 'comment:'.$second->id()], array_map($this->describe(...), $this->queries->discussion($this->task->id(), DiscussionFilter::Comments) ?? []));
        self::assertSame(['step:created', 'step:changed'], array_map($this->describe(...), $this->queries->discussion($this->task->id(), DiscussionFilter::History) ?? []));
        // The watcher a comment added is part of the comment, not a step.
        self::assertCount(2, self::getContainer()->get(TaskHistory::class)->of($this->task->id()));

        $this->signIn($this->member);
        $mine = $this->queries->discussion($this->task->id(), DiscussionFilter::Comments)[0]->comment ?? null;
        self::assertSame([true, true], [$mine?->canEdit, $mine?->canDelete]);

        $this->signIn($this->guest);
        self::assertNull($this->queries->discussion($this->task->id()));
        self::assertNull($this->queries->mentionCandidates($this->task->id()));
    }

    public function testTheMentionListHoldsActiveStaffByName(): void
    {
        $this->member->deactivate($this->clock->now());
        $this->em->flush();

        $candidates = $this->queries->mentionCandidates($this->task->id());

        self::assertSame([[$this->owner->id(), 'Olga Owner', 'OO']], array_map(static fn (MentionCandidate $person): array => [$person->id, $person->name, $person->initials], $candidates ?? []));
    }

    public function testTheFilesOfACommentAreItsAuthorsWhileItStands(): void
    {
        $permissions = self::getContainer()->get(AttachmentPermissions::class);
        $comment = $this->comments->add($this->task->id(), 'See the file');
        $file = $this->attach($comment, $this->owner->user()->id());

        self::assertTrue($permissions->canSee('task_comment', $comment->id()));
        self::assertTrue($permissions->canAttach('task_comment', $comment->id()));
        self::assertTrue($permissions->canDelete($file));
        self::assertFalse($permissions->canSee('task_comment', Id::generate()));
        $view = $this->queries->discussion($this->task->id(), DiscussionFilter::Comments)[0]->comment ?? null;
        self::assertSame(['brief.pdf'], array_map(static fn (\App\Work\Application\CommentAttachment $attachment): string => $attachment->fileName, $view->attachments ?? []));

        $this->signIn($this->member);
        self::assertTrue($permissions->canSee('task_comment', $comment->id()));
        self::assertFalse($permissions->canAttach('task_comment', $comment->id()));
        self::assertFalse($permissions->canDelete($file));

        $this->signIn($this->guest);
        self::assertFalse($permissions->canSee('task_comment', $comment->id()));

        $this->signIn($this->owner);
        $this->comments->delete($comment->id());
        self::assertFalse($permissions->canSee('task_comment', $comment->id()));
        self::assertFalse($permissions->canAttach('task_comment', $comment->id()));
    }

    private function attach(TaskComment $comment, string $userId): Attachment
    {
        $attachment = new Attachment(Id::generate(), 'task_comment', $comment->id(), 'brief.pdf', 'application/pdf', 10, str_repeat('a', 64), 'key', $userId, $this->clock->now());
        $this->em->persist($attachment);
        $this->em->flush();

        return $attachment;
    }

    private function describe(DiscussionItem $item): string
    {
        return $item->isComment() ? 'comment:'.$item->comment?->id : 'step:'.($item->step instanceof TaskHistoryEntry ? $item->step->event : '');
    }

    private function mention(string $name, string $id): string
    {
        return sprintf('@[%s](membership:%s)', $name, $id);
    }

    /**
     * @return list<string>
     */
    private function mentioned(TaskComment $comment): array
    {
        $names = array_map(static fn (CommentMention $mention): string => $mention->membership()->user()->displayName(), $this->em->getRepository(CommentMention::class)->findBy(['comment' => $comment]));
        sort($names);

        return $names;
    }

    /**
     * @return list<string>
     */
    private function watchers(): array
    {
        $names = array_map(static fn (TaskWatcher $watcher): string => $watcher->membership()->user()->displayName(), $this->em->getRepository(TaskWatcher::class)->findBy(['task' => $this->task]));
        sort($names);

        return $names;
    }

    private function assertRefused(string $reason, callable $change): void
    {
        try {
            $change();
            self::fail(sprintf('Expected the refusal "%s".', $reason));
        } catch (WorkRefused $refused) {
            self::assertSame($reason, $refused->reason);
        }
    }
}
