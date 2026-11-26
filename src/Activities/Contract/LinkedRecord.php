<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Contract;

/**
 * A record an activity can be linked to, as the person sees it (LinkableRecords::describe).
 */
final readonly class LinkedRecord
{
    /**
     * @param string|null $label the name the owning module shows for it
     * @param string|null $url   its screen
     */
    public function __construct(
        public string $kind,
        public string $id,
        public ?string $label,
        public ?string $url,
    ) {
    }
}
