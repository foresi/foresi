<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Contract;

/**
 * What a module with cost documents tells Margin: a document was recorded,
 * changed, trashed or restored, so its shares are to be counted again. It
 * changes the entity manager only; the caller flushes with its own change.
 */
interface CostSharesRecalculation
{
    public function recalculate(string $documentId): void;
}
