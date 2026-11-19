<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Column;

use Foresi\Work\Application\TaskRow;
use Foresi\Work\Infrastructure\Controller\TaskEdits;

/**
 * One row of My tasks as its columns render it: the task and the changes its row offers.
 */
final readonly class MyTaskLine
{
    public function __construct(public TaskRow $task, public TaskEdits $edits)
    {
    }
}
