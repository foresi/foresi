<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

/**
 * No movement with that id in the active tenant.
 */
final class UnknownBankMovement extends \DomainException
{
    public function __construct()
    {
        parent::__construct('The bank movement does not exist.');
    }
}
