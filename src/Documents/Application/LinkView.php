<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Application;

/**
 * A link of a document as it is shown: unavailable when the record is gone.
 */
final readonly class LinkView
{
    public function __construct(
        public string $kind,
        public string $id,
        public bool $available,
        public ?string $label,
        public ?string $url,
    ) {
    }
}
