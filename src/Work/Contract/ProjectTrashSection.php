<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Contract;

/**
 * A section of a project's trash: a titled table as Ui:DataTable takes it,
 * each row with its restore action, posted with a CSRF token of the id given.
 */
final readonly class ProjectTrashSection
{
    /**
     * @param string                     $key     lowercase letters, digits and dashes, unique on the page
     * @param list<array<string, mixed>> $columns as Ui:DataTable takes them
     * @param list<array<string, mixed>> $rows    as Ui:DataTable takes them
     * @param string                     $tokenId the id of the CSRF token the row actions post
     */
    public function __construct(
        public string $key,
        public string $title,
        public array $columns,
        public array $rows,
        public string $tokenId,
        public int $weight = 0,
    ) {
    }
}
