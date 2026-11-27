<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\View;

use Foresi\Shared\Application\View\ListView;
use Foresi\Shared\Application\View\ViewFilter;

/**
 * One table ListTable built, still without its filter sheet: what the template takes of it,
 * and what FilterSheet::of() needs to add the sheet.
 */
final readonly class ListTablePage
{
    /**
     * @param array<string, mixed>         $table  what the template takes
     * @param \Closure(ViewFilter): string $href   the table's address with another filter
     * @param list<string>                 $pinned the quick filters the view pins
     */
    public function __construct(
        public array $table,
        public ViewFilter $shown,
        public \Closure $href,
        public array $pinned,
        public ListView $view,
    ) {
    }

    /**
     * The table with its filter sheet, as FilterSheet::of() gave it.
     *
     * @param array<string, mixed> $sheet
     *
     * @return array<string, mixed>
     */
    public function with(array $sheet): array
    {
        return $this->table + $sheet;
    }
}
