<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * A document as a PDF file, with the name it is saved under.
 */
final readonly class RenderedPdf
{
    public function __construct(public string $bytes, public string $fileName)
    {
    }
}
