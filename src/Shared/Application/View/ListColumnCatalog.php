<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\View;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * The columns a list shows, for the API and MCP to describe a list (spec
 * D-display-9). The screens own the columns, so the presentation layer answers
 * this through the tag; Shared never reads it directly, and a list has no
 * columns to describe when no catalog answers.
 */
#[AutoconfigureTag(self::TAG)]
interface ListColumnCatalog
{
    public const string TAG = 'app.list_column_catalog';

    /**
     * Every column of a list in its default order: its key, its label in English,
     * whether a view may sort by it, whether it shows by default, and whether it
     * always shows.
     *
     * @return list<array{key: string, label: string, sortable: bool, default: bool, locked: bool}>
     */
    public function columns(string $listKey): array;
}
