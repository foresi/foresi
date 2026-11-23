<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Contract;

/**
 * How a document module tells Cash flow that a document exists, changed or is
 * gone, so Cash flow can pair it with a planned occurrence.
 */
interface DocumentPairer
{
    /**
     * Pairs the document, or proposes candidates, or drops a pairing that no longer holds. A pairing a person confirmed or declined stays.
     */
    public function consider(PairableDocument $document): void;

    /**
     * The document is trashed or deleted: its pairing goes, and the documents beside it are considered again.
     */
    public function forget(FlowSubject $subject): void;
}
