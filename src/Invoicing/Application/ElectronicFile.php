<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * A machine-readable copy of a document that travels inside its PDF.
 */
final readonly class ElectronicFile
{
    public function __construct(public string $name, public string $content, public string $mime)
    {
    }
}
