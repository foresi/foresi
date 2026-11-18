<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application;

use Foresi\Purchasing\Domain\ReceivedDocument;

/**
 * A document as saved, with what a person should look at about it.
 */
final readonly class SavedReceivedDocument
{
    public function __construct(
        public ReceivedDocument $document,
        public ReceivedDocumentWarnings $warnings,
    ) {
    }
}
