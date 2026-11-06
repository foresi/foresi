<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Idoklad\Application;

/**
 * What one round of PDF downloads did.
 */
final readonly class PdfDownload
{
    public function __construct(
        public int $downloaded,
        public int $requestsUsed,
    ) {
    }
}
