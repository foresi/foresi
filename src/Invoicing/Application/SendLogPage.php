<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * One page of the send log.
 */
final readonly class SendLogPage
{
    /**
     * @param list<SendLogRow>      $rows
     * @param array<string, string> $firms name by id of every firm with a send
     */
    public function __construct(public array $rows, public int $total, public int $page, public int $pages, public array $firms)
    {
    }
}
