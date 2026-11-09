<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

/**
 * One page of the inbox, newest first.
 */
final readonly class NotificationPage
{
    /**
     * @param list<NotificationRow> $rows
     */
    public function __construct(public array $rows, public int $page, public bool $hasNext)
    {
    }
}
