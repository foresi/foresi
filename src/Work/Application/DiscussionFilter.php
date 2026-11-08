<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

/**
 * What the discussion of a task shows: comments and history together, or one of them.
 */
enum DiscussionFilter: string
{
    case All = 'all';
    case Comments = 'comments';
    case History = 'history';

    public function showsComments(): bool
    {
        return self::History !== $this;
    }

    public function showsHistory(): bool
    {
        return self::Comments !== $this;
    }
}
