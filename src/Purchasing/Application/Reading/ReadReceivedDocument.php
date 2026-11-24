<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application\Reading;

/**
 * Asks the background job to read the file of a received document and fill
 * the document while it still has the base version. Manual says a person asked:
 * then the readers that only run on request (an AI reader) run too. The
 * KeepTyped says the draft was touched since its last reading: the reading then
 * fills only what the draft leaves empty and suggests the rest. The
 * correlation id groups the change the reading makes with the step that asked.
 */
final readonly class ReadReceivedDocument
{
    public function __construct(
        public string $documentId,
        public int $baseVersion,
        public bool $manual,
        public ?string $correlationId,
        public bool $keepTyped = false,
    ) {
    }
}
