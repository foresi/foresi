<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

use App\Identity\Domain\Membership;
use App\Work\Domain\NotificationPreference;

/**
 * The e-mail preferences of the people of the active tenant; a person with
 * none has the defaults.
 */
interface NotificationPreferences
{
    public function of(Membership $membership): ?NotificationPreference;

    /**
     * @return list<NotificationPreference>
     */
    public function all(): array;

    public function add(NotificationPreference $preference): void;
}
