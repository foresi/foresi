<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application\Query;

/**
 * The signed-in person may not read the payments of the organisation: nobody is
 * signed in, or they are a guest. The API answers it as forbidden.
 */
final class PaymentsNotAllowed extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Only the owners and members of the organisation read its payments.');
    }
}
