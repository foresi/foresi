<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

/**
 * Why a change to projects, tasks, labels or comments is not made; the reason is the last
 * part of a translation key under work.refused. Something the person may not
 * see is refused as unknown, exactly as something that does not exist.
 */
final class WorkRefused extends \DomainException
{
    public const array REASONS = ['unknown', 'not_allowed', 'name', 'client', 'archived', 'parent', 'responsible', 'watcher', 'last_open', 'last_done', 'target_status', 'label_taken', 'comment_empty', 'comment_too_long', 'comment_not_yours', 'comment_deleted', 'task_not_yours'];

    public function __construct(public readonly string $reason)
    {
        if (!\in_array($reason, self::REASONS, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown reason "%s".', $reason));
        }
        parent::__construct(sprintf('The change is refused: %s.', $reason));
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
}
