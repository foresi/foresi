<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Application;

/**
 * What a link of an activity shows: the record's name and screen, or that it
 * is unavailable to the person (gone, or not theirs to see).
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
