<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\ReceivedDocuments;

use Foresi\ReceivedDocuments\Application\Mail\FileMailAttachments;
use Foresi\ReceivedDocuments\Application\Reading\ReadReceivedDocument;
use Foresi\Shared\Infrastructure\Module\ModuleBundle;

/**
 * Received documents from suppliers.
 */
final class ReceivedDocumentsBundle extends ModuleBundle
{
    protected function messageRouting(): array
    {
        return [
            // The file of a received document is read after its draft was stored.
            ReadReceivedDocument::class => 'async',
            // The attachments of a mail taken in by a capture address become drafts, after the mail was stored.
            FileMailAttachments::class => 'async',
        ];
    }
}
