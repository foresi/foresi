<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Application;

/**
 * The signed-in person may not work with the received documents of the
 * organisation: nobody is signed in, or they are a guest. The screens and the
 * API answer it as forbidden.
 */
final class PurchasingNotAllowed extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Only the owners and members of the organisation work with its received documents.');
    }
}
