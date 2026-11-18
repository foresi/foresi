<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Idoklad\Application;

use Foresi\Idoklad\Application\Message\ProcessIdokladJob;

/** Port: hands a job to a worker. */
interface IdokladQueue
{
    public function dispatch(ProcessIdokladJob $message): void;
}
