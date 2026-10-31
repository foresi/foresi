<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application;

/**
 * Narrows the address book to the parties one import gave an outcome, for
 * example "the contacts this import created".
 */
final readonly class ImportedBy
{
    /** The outcomes a list can be narrowed to, as the import names them. */
    public const array OUTCOMES = ['create', 'update', 'restore', 'archive', 'changed_after_import'];

    public function __construct(
        public string $importId,
        public string $outcome,
    ) {
        if (!\in_array($outcome, self::OUTCOMES, true)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not an outcome a list can be narrowed to.', $outcome));
        }
    }
}
