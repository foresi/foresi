<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\View;

use Foresi\Shared\Domain\View\SavedView;

interface SavedViewStore
{
    /**
     * The views of a list, hidden ones too, shared ones first and then by position.
     *
     * @return list<SavedView>
     */
    public function ofList(string $listKey): array;

    public function bySystemKey(string $systemKey): ?SavedView;

    public function add(SavedView $view): void;

    public function flush(): void;
}
