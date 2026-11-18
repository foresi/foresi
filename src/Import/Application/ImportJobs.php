<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Import\Application;

/** Hands work to the background workers. */
interface ImportJobs
{
    public function dispatch(object $message): void;
}
