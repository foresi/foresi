<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Contract\Navigation;

/**
 * Supplies the number shown next to a navigation item. It is only called for a signed-in
 * person with an active membership, so it may use the instance's data. Returning null shows no number.
 */
interface NavigationCountProvider
{
    public function count(): ?int;
}
