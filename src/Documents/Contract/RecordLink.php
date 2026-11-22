<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Contract;

/**
 * A record a document belongs to, such as the contact it was made for. The kind
 * is the record kind shared by all modules ("contact", "deal"); a link to a kind
 * that no DocumentLinkTarget claims is refused.
 */
final readonly class RecordLink
{
    public function __construct(public string $kind, public string $id)
    {
    }
}
