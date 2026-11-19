<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application;

/**
 * One page of the send log.
 */
final readonly class SendLogPage
{
    /**
     * @param list<SendLogRow> $rows
     * @param bool             $anySent whether anything was ever sent, whatever the filter holds
     */
    public function __construct(public array $rows, public int $total, public int $page, public int $pages, public bool $anySent)
    {
    }
}
