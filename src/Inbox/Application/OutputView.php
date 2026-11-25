<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Application;

use Foresi\Inbox\Domain\ItemOutput;

/**
 * An output of an item as the person sees its record: its name and screen from
 * the module that owns it, or unavailable when the record is gone, not theirs
 * to see, or of a kind no module offers to activities.
 */
final readonly class OutputView
{
    public function __construct(
        public ItemOutput $output,
        public bool $available,
        public ?string $label,
        public ?string $url,
    ) {
    }
}
