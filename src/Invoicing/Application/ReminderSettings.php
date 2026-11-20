<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application;

use Foresi\Invoicing\Domain\FirmReminders;

/**
 * The reminder settings of the firm. Call save() inside
 * TransactionalWork, which flushes.
 */
interface ReminderSettings
{
    /**
     * The stored settings, or the standard ones, switched off and not stored yet.
     */
    public function get(): FirmReminders;

    public function save(FirmReminders $settings): void;

    /**
     * Whether automatic reminders may be switched on: only once open amounts can be
     * believed, unless the installation allows it without them (the tests do).
     */
    public function canSwitchOn(): bool;
}
