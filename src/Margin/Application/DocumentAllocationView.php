<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Application;

/**
 * What the margin knows of one cost document: the mapping of the whole
 * document, each line with its mapping and shares, and what cannot be
 * counted yet (known_gaps, such as missing_exchange_rate).
 */
final readonly class DocumentAllocationView
{
    /**
     * @param list<LineAllocationView> $lines
     * @param list<string>             $knownGaps
     */
    public function __construct(
        public string $documentId,
        public string $label,
        public string $url,
        public bool $trashed,
        public ?string $documentRecipient,
        public ?string $documentRecipientName,
        public array $lines,
        public array $knownGaps,
    ) {
    }
}
