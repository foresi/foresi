<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Navigation;

/**
 * Supplies the number shown next to a navigation item. It runs inside the
 * active tenant and is never called when there is none, so it may use
 * tenant-owned data. Returning null shows no number.
 */
interface NavigationCountProvider
{
    public function count(): ?int;
}
