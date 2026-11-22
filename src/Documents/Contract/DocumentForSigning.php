<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Contract;

/**
 * A ready document as a signature provider receives it. Designed, not built
 * (see SignatureProvider).
 */
final readonly class DocumentForSigning
{
    /**
     * @param list<array{name: string, email: ?string, order: int}> $signers
     */
    public function __construct(
        public string $documentId,
        public string $title,
        public string $pdf,
        public string $signatureLevel,
        public array $signers,
    ) {
    }
}
