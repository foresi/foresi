<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Events\Application;

/**
 * Asks the relay to look for committed events that have no position yet. Sent
 * after a commit that recorded events; carries nothing, because the relay reads
 * the log itself.
 */
final readonly class RelayEvents
{
}
