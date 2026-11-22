<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Application;

use Foresi\Documents\Domain\DocumentStatus;

/**
 * Which documents a list shows: of a type, in a status, linked to a record.
 */
final readonly class DocumentFilter
{
    public const int MAX_LIMIT = 200;

    public function __construct(
        public ?string $typeId = null,
        public ?DocumentStatus $status = null,
        public ?string $linkKind = null,
        public ?string $linkId = null,
        public int $limit = 50,
        public int $offset = 0,
    ) {
    }
}
