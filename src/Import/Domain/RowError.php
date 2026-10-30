<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Domain;

/** A problem with one field of one source row; the message is a translation key. */
final readonly class RowError
{
    /**
     * @param array<string, int|string> $parameters
     */
    public function __construct(
        public string $field,
        public string $messageKey,
        public array $parameters = [],
    ) {
    }
}
