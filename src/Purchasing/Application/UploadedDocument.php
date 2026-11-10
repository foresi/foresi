<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Application;

/**
 * What became of one uploaded file: the draft it became, with its warnings,
 * or why the attachment store refused it (an AttachmentRefused reason), in
 * which case no draft was kept.
 */
final readonly class UploadedDocument
{
    private function __construct(
        public string $fileName,
        public ?string $documentId,
        public ReceivedDocumentWarnings $warnings,
        public ?string $refusal,
    ) {
    }

    public static function drafted(string $fileName, string $documentId, ReceivedDocumentWarnings $warnings): self
    {
        return new self($fileName, $documentId, $warnings, null);
    }

    public static function refused(string $fileName, string $reason): self
    {
        return new self($fileName, null, new ReceivedDocumentWarnings(), $reason);
    }

    public function isDrafted(): bool
    {
        return null !== $this->documentId;
    }
}
