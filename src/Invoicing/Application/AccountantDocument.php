<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\ExportState;

/**
 * A document of a pack for the accountant, with where it stood before the pack.
 */
final readonly class AccountantDocument
{
    public function __construct(public IssuedDocument $document, public ExportState $state)
    {
    }
}
