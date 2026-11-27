<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Application;

use Foresi\Inbox\Domain\Suggestion;

/**
 * What came of accepting one suggestion of a call: the suggestion as it is
 * now, its steps with their states and refusals, or the reason it was not
 * decided at all (not_found, not_open, item_trashed), which stopped none of
 * the others.
 */
final readonly class Decision
{
    /**
     * @param Suggestion|null $suggestion null when it is not the person's or does not exist
     * @param string|null     $refused    the reason the suggestion itself was refused; null when its steps ran
     */
    public function __construct(
        public string $suggestionId,
        public ?Suggestion $suggestion,
        public ?string $refused = null,
    ) {
    }
}
