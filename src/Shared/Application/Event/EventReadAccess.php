<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Application\Event;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Tells whether the current caller may read the events of one module, so the
 * feed and the tools show a module's events only to those who may see its data.
 */
#[AutoconfigureTag('app.event_read_access')]
interface EventReadAccess
{
    /**
     * The id of the module whose events this decides on.
     */
    public function module(): string;

    public function mayRead(): bool;
}
