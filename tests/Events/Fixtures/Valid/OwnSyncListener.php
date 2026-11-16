<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Events\Fixtures\Valid;

/**
 * A synchronous listener in the module of the event it listens to.
 */
final class OwnSyncListener
{
    public function __invoke(): void
    {
    }
}
