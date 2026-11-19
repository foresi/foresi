<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application;

use Foresi\Shared\Application\RefusalKind;
use Foresi\Shared\Application\Refused;

/**
 * Why a change to projects, tasks, labels, comments or time is not made; the
 * reason is the last part of a translation key under work.refused, and the
 * code the API answers (ADR 0032), unknown as not_found and not_allowed as
 * forbidden. Something the person may not see is
 * refused as unknown, exactly as something that does not exist.
 */
final class WorkRefused extends \DomainException implements Refused
{
    public const array REASONS = ['unknown', 'not_allowed', 'name', 'client', 'archived', 'parent', 'responsible', 'watcher', 'last_open', 'last_done', 'target_status', 'label_taken', 'label_restore_taken', 'comment_empty', 'comment_too_long', 'comment_not_yours', 'comment_deleted', 'comment_purged', 'task_not_yours', 'parent_in_trash', 'share_person', 'duration', 'time_note', 'time_task', 'time_not_yours', 'time_invoiced', 'time_task_in_trash', 'timer_running', 'timer_not_running', 'estimate', 'date'];

    /** What the person may not do, whatever they send: 403. */
    private const array FORBIDDEN = ['not_allowed', 'comment_not_yours', 'task_not_yours', 'time_not_yours'];

    /** What the data does not allow now: 409. */
    private const array CONFLICTS = ['archived', 'last_open', 'last_done', 'label_taken', 'label_restore_taken', 'comment_deleted', 'comment_purged', 'parent_in_trash', 'time_invoiced', 'time_task_in_trash', 'timer_running', 'timer_not_running'];

    /** The input field a reason is about, where it is always the same one. */
    private const array FIELDS = [
        'client' => 'clientId', 'parent' => 'parentId', 'responsible' => 'responsibleId', 'comment_empty' => 'body', 'comment_too_long' => 'body',
        'duration' => 'minutes', 'time_note' => 'note', 'time_task' => 'taskId', 'estimate' => 'estimateMinutes',
    ];

    /** For people and agents; never quoting what someone wrote. */
    private const array MESSAGES = [
        'unknown' => 'No such project, section, status, task, label, comment or time entry, or not one the person sees.',
        'not_allowed' => 'Only the owners and team members of the organisation may do this.',
        'name' => 'The name or title is empty or too long.',
        'client' => 'The client must be a contact of the address book.',
        'archived' => 'The project is archived; restore it first.',
        'parent' => 'A subtask goes under a task of the same project that is not a subtask itself.',
        'responsible' => 'The responsible must be an active person of the organisation who sees the task; find them with foresi_list_project_people.',
        'watcher' => 'A watcher must be an active person of the organisation who sees the task.',
        'last_open' => 'The project must keep at least one status of type open.',
        'last_done' => 'The project must keep at least one status of type done.',
        'target_status' => 'Choose another status of the same project for its tasks.',
        'label_taken' => 'A label of this name exists already.',
        'label_restore_taken' => 'A label of this name exists already; rename it first.',
        'comment_empty' => 'The comment is empty.',
        'comment_too_long' => 'A comment has at most 20,000 characters.',
        'comment_not_yours' => 'Only the author of the comment may change it; its author or an owner may delete it.',
        'comment_deleted' => 'The comment is deleted.',
        'comment_purged' => 'The text of the comment is deleted for good.',
        'task_not_yours' => 'Only whoever created the task or an owner of the organisation may put it in the trash or restore it.',
        'parent_in_trash' => 'The task of this subtask is in the trash; restore it first.',
        'share_person' => 'Share with an active person of the organisation.',
        'duration' => 'The time is at least a minute and at most a day: minutes, or a start and an end after it, not both.',
        'time_note' => 'A note of time has at most 2,000 characters.',
        'time_task' => 'The task must be one of the project.',
        'time_not_yours' => 'Only whoever logged the time or an owner of the organisation may change it.',
        'time_invoiced' => 'The time is invoiced and no longer changes.',
        'time_task_in_trash' => 'The task of the time is in the trash; restore it first.',
        'timer_running' => 'The timer is running; stop it first.',
        'timer_not_running' => 'No timer of the person runs.',
        'estimate' => 'An estimate is at least a minute and at most 9,999 hours.',
        'date' => 'A date is YYYY-MM-DD and a moment ISO 8601 with its offset, such as 2026-11-19T09:30:00+01:00.',
    ];

    public function __construct(public readonly string $reason, private readonly ?string $field = null)
    {
        if (!\in_array($reason, self::REASONS, true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown reason "%s".', $reason));
        }
        parent::__construct(self::MESSAGES[$reason]);
    }

    /**
     * Runs a change that names something, refusing a name the domain refuses.
     *
     * @template T
     *
     * @param callable(): T $change
     *
     * @return T
     */
    public static function unlessNamed(callable $change): mixed
    {
        try {
            return $change();
        } catch (WorkRefused $refused) {
            throw $refused;
        } catch (\DomainException) {
            throw new self('name');
        }
    }

    /**
     * The same refusal, about the field the input names it by, such as title.
     */
    public function about(string $field): self
    {
        return new self($this->reason, $field);
    }

    /**
     * The code of the API: unknown and not_allowed as every module answers them, not_found and forbidden.
     */
    public function reason(): string
    {
        return match ($this->reason) {
            'unknown' => 'not_found',
            'not_allowed' => 'forbidden',
            default => $this->reason,
        };
    }

    public function kind(): RefusalKind
    {
        return match (true) {
            'unknown' === $this->reason => RefusalKind::NotFound,
            \in_array($this->reason, self::FORBIDDEN, true) => RefusalKind::Forbidden,
            \in_array($this->reason, self::CONFLICTS, true) => RefusalKind::Conflict,
            default => RefusalKind::Invalid,
        };
    }

    public function field(): ?string
    {
        return $this->field ?? self::FIELDS[$this->reason] ?? null;
    }

    public function details(): array
    {
        return [];
    }
}
