<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * Rolls an undo back when what it takes along is blocked. Never leaves
 * UndoImportedDocuments.
 *
 * @internal
 */
final class UndoRefused extends \RuntimeException
{
    /**
     * @param list<UndoBlocker> $blockers
     */
    public function __construct(public readonly array $blockers)
    {
        parent::__construct('The undo was blocked.');
    }
}
