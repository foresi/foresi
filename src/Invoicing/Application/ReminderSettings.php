<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\FirmReminders;
use App\Party\Domain\LegalEntity;

/**
 * The reminder settings of the firms of the active tenant. Call save() inside
 * TransactionalWork, which flushes.
 */
interface ReminderSettings
{
    /**
     * The stored settings of a firm, or the standard ones, switched off and not stored yet.
     */
    public function for(LegalEntity $firm): FirmReminders;

    public function save(FirmReminders $settings): void;

    /**
     * Whether automatic reminders may be switched on: only once open amounts can be
     * believed, unless the installation allows it without them (the tests do).
     */
    public function canSwitchOn(): bool;
}
