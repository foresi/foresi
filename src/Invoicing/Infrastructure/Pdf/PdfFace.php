<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Pdf;

/**
 * A bundled font face: the family and style templates name it by, and its metrics file.
 */
final readonly class PdfFace
{
    public function __construct(
        public string $family,
        public string $style,
        public string $file,
    ) {
    }
}
