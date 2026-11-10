<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * The signed-in person may not work with the invoices of the organisation:
 * nobody is signed in, or they are a guest. The screens and the API answer it
 * as forbidden.
 */
final class InvoicingNotAllowed extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Only the owners and members of the organisation work with its invoices.');
    }
}
