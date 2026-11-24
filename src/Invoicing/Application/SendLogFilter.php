<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application;

use Foresi\Shared\Application\View\ViewFilter;

/**
 * Which sends the send log shows, newest first: those the view's filter holds
 * (SendLogLists) whose number, customer, subject or recipients hold a text, on one page.
 */
final readonly class SendLogFilter
{
    public const int PER_PAGE = 25;

    public function __construct(
        public ViewFilter $view = new ViewFilter(),
        public int $page = 1,
        public string $text = '',
    ) {
    }
}
