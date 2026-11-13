<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Application\Event;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Tells whether the current caller may read the events of one module, so the
 * feed and the tools show a module's events only to those who may see its data;
 * and whether a given person may, for work done on their behalf, such as the
 * webhooks they own.
 */
#[AutoconfigureTag('app.event_read_access')]
interface EventReadAccess
{
    /**
     * The id of the module whose events this decides on.
     */
    public function module(): string;

    public function mayRead(): bool;

    /**
     * Whether a given person, signed in or not, may read these events in the
     * active organisation. False when no organisation is active.
     */
    public function mayUserRead(string $userId): bool;
}
