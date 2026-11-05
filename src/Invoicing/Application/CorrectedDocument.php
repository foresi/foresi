<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\DocumentKind;

/**
 * The document a corrective tax document corrects, as an electronic file names it.
 */
final readonly class CorrectedDocument
{
    public function __construct(
        public string $id,
        public DocumentKind $kind,
        public string $number,
        public \DateTimeImmutable $issueDate,
    ) {
    }
}
