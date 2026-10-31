<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Infrastructure\Controller;

use App\Import\Domain\RowOutcome;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * The pages of an import target the import screens link to: the list of its
 * records, narrowed to what one import did with them. A module implements it
 * next to its ImportTarget (tag "app.import_target_pages").
 */
#[AutoconfigureTag(TargetPagesRegistry::TAG)]
interface TargetPages
{
    public function targetKey(): string;

    /** Translation key of the name of the list, for example "Address book". */
    public function listLabelKey(): string;

    /**
     * The list of records; with an import, only those the import gave this outcome.
     * Null when the list cannot be narrowed to the outcome.
     */
    public function listUrl(?int $importId = null, ?RowOutcome $outcome = null): ?string;
}
