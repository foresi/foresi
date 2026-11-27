<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Contacts\Contract;

/**
 * A column of ContactImportColumns, described as the import describes its
 * fields: its type is the value of one of the import's field types, such as
 * "integer" or "choice".
 */
final readonly class ContactImportColumn
{
    /**
     * @param list<string> $aliases the column headings it is found by, in lower case without accents
     * @param list<string> $choices the values of a choice
     */
    public function __construct(
        public string $key,
        public string $labelKey,
        public string $type,
        public array $aliases = [],
        public array $choices = [],
    ) {
    }
}
