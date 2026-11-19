<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application;

use Foresi\Work\Domain\LabelColour;

/**
 * A label in the trash of the labels.
 */
final readonly class TrashedLabel
{
    public function __construct(
        public string $id,
        public string $name,
        public LabelColour $colour,
        public ?string $deletedByName,
        public \DateTimeImmutable $deletedAt,
    ) {
    }
}
