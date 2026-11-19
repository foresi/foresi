<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application;

use Foresi\Audit\Application\AuditActor;
use Foresi\Identity\Application\OwnerCheck;
use Foresi\Identity\Application\People;
use Foresi\Identity\Domain\Membership;
use Foresi\Shared\Application\Event\EventRecorder;
use Foresi\Shared\Application\MentionSyntax;
use Foresi\Work\Contract\Event\CommentAdded;
use Foresi\Work\Domain\CommentMention;
use Foresi\Work\Domain\Task;
use Foresi\Work\Domain\TaskComment;
use Foresi\Work\Domain\TaskWatcher;
use Psr\Clock\ClockInterface;

/**
 * Writing on a task. Whoever sees a task comments on it. A comment is public
 * unless said otherwise, as in Linear, Asana and Zendesk: everyone who sees the
 * task reads it. The people of the organisation may make one internal; a guest
 * never reads an internal comment, nor writes one. The author alone edits or
 * deletes a comment; an owner of the organisation may also delete one, to
 * moderate, but never puts words in someone else's mouth. Whoever comments
 * follows the task, as in ClickUp and Asana, and so does whoever it mentions,
 * as in Basecamp, Linear and GitHub.
 *
 * The people a comment mentions are kept as rows beside it, in step with its
 * text: only active people who can see the task, and the comment when it is
 * internal, whatever the text claims. They hear of the mention, the other
 * watchers of the comment; an edit tells only the people it adds.
 *
 * A deleted comment keeps its text for 30 days, so whoever may delete it may
 * restore it, as GitHub and Linear keep a deleted comment in the thread. While
 * deleted it mentions nobody; restoring brings its mentions back and tells
 * nobody again, since they heard of it when it was written.
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
        private EventRecorder $events,
    ) {
    }

    /**
     * @throws WorkRefused
     */
    public function add(string $taskId, string $body, bool $internal = false): TaskComment
    {
        $task = $this->work->task($taskId);
        $author = $this->commenter($task);
        $comment = new TaskComment($task, $author, $this->body($body), $internal && $this->visibility->canSeeInternal(), $this->clock->now());

        return $this->unit->run(function () use ($task, $author, $comment): TaskComment {
            $this->comments->add($comment);
            $mentioned = $this->syncMentions($comment, []);
            $this->watch($task, [$author, ...$mentioned]);
            $this->notifier->commented($comment, $mentioned);
            $this->events->record(new CommentAdded($comment->id(), $task->id(), $task->project()->id(), $comment->isInternal()));

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
    public function delete(string $commentId): TaskComment
    {
        $comment = $this->comment($commentId);
        $userId = $this->moderator($comment);
        $this->unit->run(function () use ($comment, $userId): void {
            $comment->delete($userId, $this->clock->now());
            foreach ($this->comments->mentions($comment) as $mention) {
                $this->comments->remove($mention);
            }
        });

        return $comment;
    }

    /**
     * Brings back the text of a deleted comment and the people it mentions,
     * without telling them again. Restoring a comment that stands changes nothing.
     *
     * @throws WorkRefused
     */
    public function restore(string $commentId): TaskComment
    {
        $comment = $this->comment($commentId);
        $this->moderator($comment);
        if (!$comment->isDeleted()) {
            return $comment;
        }
        if (!$comment->isRestorable()) {
            throw new WorkRefused('comment_purged');
        }
        $this->unit->run(function () use ($comment): void {
            $comment->restore();
            $this->syncMentions($comment, $this->comments->mentions($comment));
        });

        return $comment;
    }

    /**
     * Its author or an owner of the organisation, who may delete or restore it.
     *
     * @return string the user id of who does it
     *
     * @throws WorkRefused
     */
    private function moderator(TaskComment $comment): string
    {
        $me = $this->commenter($comment->task());
        if ($comment->author()->id() !== $me->id() && !$this->owner->isOwner()) {
            throw new WorkRefused('comment_not_yours');
        }

        return $me->user()->id();
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
