<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

/**
 * Port: whether the signed-in user owns the active organisation. Some bank
 * actions, such as recording a message that failed the sender check, are the
 * owner's alone, and the rule holds in the application, not only on the screen.
 */
interface Ownership
{
    public function isOwner(): bool;
}
