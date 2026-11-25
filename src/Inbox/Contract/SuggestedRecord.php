<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Contract;

/**
 * A record an action on an inbox item pre-selects, such as the one open deal of
 * the matched client, by the kind and id an ActivityTarget knows it by.
 */
final readonly class SuggestedRecord
{
    public function __construct(public string $kind, public string $id)
    {
    }
}
