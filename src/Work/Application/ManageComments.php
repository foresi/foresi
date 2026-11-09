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
 * Writing on a task. Whoever sees a task comments on it. A comment of the
 * people of the organisation is internal unless said otherwise; a guest never
 * reads an internal comment, nor writes one. The author alone edits or
 * deletes a comment; an owner of the organisation may also delete one, to
 * moderate, but never puts words in someone else's mouth. Whoever comments
 * follows the task, as in ClickUp and Asana, and so does whoever it mentions,
 * as in Basecamp, Linear and GitHub.
 *
 * The people a comment mentions are kept as rows beside it, in step with its
 * text: only active people who can see the task, and the comment when it is
 * internal, whatever the text claims. They hear of the mention, the other
 * watchers of the comment; an edit tells only the people it adds.
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
        private Notifier $notifier,
    ) {
    }

    /**
     * @throws WorkRefused
     */
    public function add(string $taskId, string $body, bool $internal = true): TaskComment
    {
        $task = $this->work->task($taskId);
        $author = $this->commenter($task);
        $comment = new TaskComment($task, $author, $this->body($body), $internal && $this->visibility->canSeeInternal(), $this->clock->now());

        return $this->unit->run(function () use ($task, $author, $comment): TaskComment {
            $this->comments->add($comment);
            $mentioned = $this->syncMentions($comment, []);
            $this->watch($task, [$author, ...$mentioned]);
            $this->notifier->commented($comment, $mentioned);

            return $comment;
        });
    }

    /**
     * @throws WorkRefused
     */
    public function edit(string $commentId, string $body): void
    {
        $comment = $this->comment($commentId);
        if ($comment->author()->id() !== $this->commenter($comment->task())->id()) {
            throw new WorkRefused('comment_not_yours');
        }
        if ($comment->isDeleted()) {
            throw new WorkRefused('comment_deleted');
        }
        $body = $this->body($body);
        $this->unit->run(function () use ($comment, $body): void {
            $comment->edit($body, $this->clock->now());
            $mentioned = $this->syncMentions($comment, $this->comments->mentions($comment));
            $this->watch($comment->task(), $mentioned);
            $this->notifier->mentioned($comment, $mentioned);
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
        if ($comment->author()->id() !== $this->commenter($comment->task())->id() && !$this->owner->isOwner()) {
            throw new WorkRefused('comment_not_yours');
        }
        $this->unit->run(function () use ($comment): void {
            $comment->delete($this->clock->now());
            $this->syncMentions($comment, $this->comments->mentions($comment));
        });
    }

    /**
     * @param list<CommentMention> $current
     *
     * @return list<Membership> the people mentioned anew
     */
    private function syncMentions(TaskComment $comment, array $current): array
    {
        $wanted = [];
        foreach (MentionSyntax::ids($comment->text()) as $id) {
            $membership = $this->people->membership($id);
            if ($membership instanceof Membership && $this->visibility->canMembershipSeeTask($membership, $comment->task()) && (!$comment->isInternal() || $membership->role()->isStaff())) {
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

        return array_values($wanted);
    }

    /**
     * Each person follows the task once, however often they are named.
     *
     * @param list<Membership> $memberships
     */
    private function watch(Task $task, array $memberships): void
    {
        $watching = [];
        foreach ($this->tasks->watchers($task) as $watcher) {
            $watching[$watcher->membership()->id()] = true;
        }
        foreach ($memberships as $membership) {
            if (!isset($watching[$membership->id()])) {
                $watching[$membership->id()] = true;
                $this->tasks->add(new TaskWatcher($task, $membership));
            }
        }
    }

    /**
     * A comment the person may read, on a task they may see.
     *
     * @throws WorkRefused
     */
    private function comment(string $id): TaskComment
    {
        $comment = $this->comments->comment($id);
        if (!$comment instanceof TaskComment || ($comment->isInternal() && !$this->visibility->canSeeInternal())) {
            throw new WorkRefused('unknown');
        }
        $this->work->task($comment->task()->id());

        return $comment;
    }

    /**
     * The active membership of the signed-in person, who may comment on the task.
     *
     * @throws WorkRefused
     */
    private function commenter(Task $task): Membership
    {
        if (!$this->visibility->canComment($task)) {
            throw new WorkRefused('not_allowed');
        }
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
