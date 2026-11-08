<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

use App\Audit\Application\AuditActor;
use App\Identity\Application\OwnerCheck;
use App\Identity\Application\People;
use App\Identity\Domain\Membership;
use App\Shared\Application\MentionSyntax;
use App\Work\Domain\CommentMention;
use App\Work\Domain\Task;
use App\Work\Domain\TaskComment;
use App\Work\Domain\TaskWatcher;
use Psr\Clock\ClockInterface;

/**
 * Writing on a task. Only the people of the organisation comment for now, and
 * a new comment is internal unless said otherwise. The author alone edits or
 * deletes a comment; an owner of the organisation may also delete one, to
 * moderate, but never puts words in someone else's mouth. Whoever comments
 * follows the task, as in ClickUp and Asana.
 *
 * The people a comment mentions are kept as rows beside it, in step with its
 * text: only active people who can see the task, whatever the text claims.
 */
final readonly class ManageComments
{
    public function __construct(
        private Comments $comments,
        private Tasks $tasks,
        private VisibleWork $work,
        private WorkVisibility $visibility,
        private People $people,
        private OwnerCheck $owner,
        private AuditActor $actor,
        private ClockInterface $clock,
        private WorkUnit $unit,
    ) {
    }

    /**
     * @throws WorkRefused
     */
    public function add(string $taskId, string $body, bool $internal = true): TaskComment
    {
        $task = $this->work->task($taskId);
        $author = $this->commenter();
        $comment = new TaskComment($task, $author, $this->body($body), $internal, $this->clock->now());

        return $this->unit->run(function () use ($task, $author, $comment): TaskComment {
            $this->comments->add($comment);
            $this->syncMentions($comment, []);
            $this->watch($task, $author);

            return $comment;
        });
    }

    /**
     * @throws WorkRefused
     */
    public function edit(string $commentId, string $body): void
    {
        $comment = $this->comment($commentId);
        if ($comment->author()->id() !== $this->commenter()->id()) {
            throw new WorkRefused('comment_not_yours');
        }
        if ($comment->isDeleted()) {
            throw new WorkRefused('comment_deleted');
        }
        $body = $this->body($body);
        $this->unit->run(function () use ($comment, $body): void {
            $comment->edit($body, $this->clock->now());
            $this->syncMentions($comment, $this->comments->mentions($comment));
        });
    }

    /**
     * Deleting a deleted comment changes nothing.
     *
     * @throws WorkRefused
     */
    public function delete(string $commentId): void
    {
        $comment = $this->comment($commentId);
        if ($comment->author()->id() !== $this->commenter()->id() && !$this->owner->isOwner()) {
            throw new WorkRefused('comment_not_yours');
        }
        $this->unit->run(function () use ($comment): void {
            $comment->delete($this->clock->now());
            $this->syncMentions($comment, $this->comments->mentions($comment));
        });
    }

    /**
     * @param list<CommentMention> $current
     */
    private function syncMentions(TaskComment $comment, array $current): void
    {
        $wanted = [];
        foreach (MentionSyntax::ids($comment->text()) as $id) {
            $membership = $this->people->membership($id);
            if ($membership instanceof Membership && $membership->isActive() && $this->visibility->canMembershipSeeTask($membership, $comment->task())) {
                $wanted[$membership->id()] = $membership;
            }
        }
        foreach ($current as $mention) {
            if (isset($wanted[$mention->membership()->id()])) {
                unset($wanted[$mention->membership()->id()]);
            } else {
                $this->comments->remove($mention);
            }
        }
        foreach ($wanted as $membership) {
            $this->comments->add(new CommentMention($comment, $membership));
        }
    }

    private function watch(Task $task, Membership $membership): void
    {
        foreach ($this->tasks->watchers($task) as $watcher) {
            if ($watcher->membership()->id() === $membership->id()) {
                return;
            }
        }
        $this->tasks->add(new TaskWatcher($task, $membership));
    }

    /**
     * A comment on a task the person may see.
     *
     * @throws WorkRefused
     */
    private function comment(string $id): TaskComment
    {
        $comment = $this->comments->comment($id);
        if (!$comment instanceof TaskComment) {
            throw new WorkRefused('unknown');
        }
        $this->work->task($comment->task()->id());

        return $comment;
    }

    /**
     * The active membership of the signed-in person, who works for the organisation.
     *
     * @throws WorkRefused
     */
    private function commenter(): Membership
    {
        $this->work->assertManager();
        $userId = $this->actor->userId() ?? throw new WorkRefused('not_allowed');
        $membership = $this->people->membershipOf($userId);
        if (!$membership instanceof Membership || !$membership->isActive()) {
            throw new WorkRefused('not_allowed');
        }

        return $membership;
    }

    /**
     * The body as the comment keeps it.
     *
     * @throws WorkRefused when it is empty or too long
     */
    private function body(string $body): string
    {
        $body = trim($body);
        if ('' === $body) {
            throw new WorkRefused('comment_empty');
        }
        if (mb_strlen($body) > TaskComment::MAX_BODY_LENGTH) {
            throw new WorkRefused('comment_too_long');
        }

        return $body;
    }
}
