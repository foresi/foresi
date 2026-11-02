<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * One coming invoice of a recurring template as the form previews it: its dates
 * and its description with the period variables resolved for its occurrence.
 */
final readonly class PreviewRow
{
    public function __construct(
        public \DateTimeImmutable $issue,
        public \DateTimeImmutable $taxPoint,
        public \DateTimeImmutable $due,
        public string $description,
    ) {
    }
}
