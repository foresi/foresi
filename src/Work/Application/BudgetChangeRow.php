<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application;

/**
 * One change of a project's budget as the change log shows it, with the name
 * of who entered it.
 */
final readonly class BudgetChangeRow
{
    public function __construct(
        public string $id,
        public \DateTimeImmutable $effectiveOn,
        public int $minutes,
        public string $note,
        public ?string $sourceActivityId,
        public string $createdBy,
        public ?string $authorName,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
