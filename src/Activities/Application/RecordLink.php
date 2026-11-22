<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Application;

/**
 * A record an activity is about, by the record kind shared by all modules
 * ("contact", "deal") and its id.
 */
final readonly class RecordLink
{
    public function __construct(public string $kind, public string $id)
    {
    }

    /** As events name it: "kind:id". */
    public function name(): string
    {
        return $this->kind.':'.$this->id;
    }
}
