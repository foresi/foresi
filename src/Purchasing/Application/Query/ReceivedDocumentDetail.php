<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Application\Query;

use App\Attachment\Domain\Attachment;
use App\Purchasing\Domain\ReceivedDocument;

/**
 * A received document as its page shows it: the document, its original file,
 * the document a credit note corrects, the documents it could correct and its
 * history.
 */
final readonly class ReceivedDocumentDetail
{
    /**
     * @param list<ReceivedDocumentRow>          $correctable the recorded documents of the firm a credit note may name, newest first
     * @param list<ReceivedDocumentHistoryEntry> $history     oldest first
     */
    public function __construct(
        public ReceivedDocument $document,
        public ?Attachment $original,
        public ?ReceivedDocumentRow $corrected,
        public array $correctable,
        public array $history,
    ) {
    }
}
