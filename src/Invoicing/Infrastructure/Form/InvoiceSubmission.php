<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Form;

use App\Invoicing\Application\InvoiceInput;

/**
 * The invoice form as sent. The input is always there, with neutral values where
 * the form held a mistake, so the rules can still name everything else that is
 * wrong; with errors it must not be saved.
 */
final readonly class InvoiceSubmission
{
    /**
     * @param array<string, mixed>  $values  the form as typed, to show it again
     * @param array<string, string> $errors  translation keys by field
     * @param int                   $version the version of the invoice when the form was opened
     */
    public function __construct(public array $values, public array $errors, public InvoiceInput $input, public int $version)
    {
    }
}
