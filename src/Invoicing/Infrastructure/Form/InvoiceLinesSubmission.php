<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Form;

use Foresi\Invoicing\Application\LineInput;

/**
 * The lines of a form on their own, row for row, for a recount.
 */
final readonly class InvoiceLinesSubmission
{
    /**
     * @param list<LineInput>             $lines
     * @param list<array<string, string>> $values the rows as typed
     * @param array<string, string>       $errors translation keys by field
     */
    public function __construct(public array $lines, public array $values, public array $errors)
    {
    }
}
