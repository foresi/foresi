<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Markdown;

use League\CommonMark\Node\Inline\AbstractInline;

/**
 * A mention of a person in the parsed document.
 */
final class Mention extends AbstractInline
{
    public function __construct(
        public readonly string $displayName,
        public readonly string $membershipId,
    ) {
        parent::__construct();
    }
}
