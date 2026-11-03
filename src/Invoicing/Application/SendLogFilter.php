<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\SendStatus;

/**
 * Which sends the send log shows: of one firm or all, in one state or all, newest
 * first.
 */
final readonly class SendLogFilter
{
    public const int PER_PAGE = 25;

    public function __construct(
        public ?string $firmId = null,
        public ?SendStatus $status = null,
        public int $page = 1,
    ) {
    }
}
