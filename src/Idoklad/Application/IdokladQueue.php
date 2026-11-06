<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Idoklad\Application;

use App\Idoklad\Application\Message\ProcessIdokladJob;

/** Port: hands a job to a worker, in the active tenant. */
interface IdokladQueue
{
    public function dispatch(ProcessIdokladJob $message): void;
}
