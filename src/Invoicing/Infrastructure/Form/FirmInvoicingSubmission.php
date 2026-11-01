<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Form;

/**
 * What came in from an invoicing form: the values to show again and a message
 * per field that is wrong.
 */
final readonly class FirmInvoicingSubmission
{
    /**
     * @param array<string, string>                                      $values
     * @param array<string, array{0: string, 1?: array<string, string>}> $errors field => translation key and its parameters
     */
    public function __construct(public array $values, public array $errors)
    {
    }
}
