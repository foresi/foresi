<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Events\Fixtures\Stranger;

/**
 * A synchronous listener in another module than the event's, which the rule forbids.
 */
final class ForeignSyncListener
{
}
