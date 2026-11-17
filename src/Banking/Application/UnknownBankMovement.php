<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

/**
 * No movement with that id.
 */
final class UnknownBankMovement extends \DomainException
{
    public function __construct()
    {
        parent::__construct('The bank movement does not exist.');
    }
}
