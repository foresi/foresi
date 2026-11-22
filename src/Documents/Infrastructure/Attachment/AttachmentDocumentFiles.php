<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Infrastructure\Attachment;

use Foresi\Attachment\Application\AttachmentRefused;
use Foresi\Attachment\Application\AttachmentUpload;
use Foresi\Attachment\Application\UploadAttachment;
use Foresi\Documents\Application\DocumentFiles;
use Foresi\Documents\Application\DocumentsRefused;
use Foresi\Documents\Application\StoredFile;
use Foresi\Documents\Domain\Document;

/**
 * Keeps the PDF and the signed copy of a document as attachments of kind
 * "document", through the upload of the Attachment module.
 */
final readonly class AttachmentDocumentFiles implements DocumentFiles
{
    public function __construct(private UploadAttachment $upload)
    {
    }

    public function store(string $documentId, string $fileName, string $bytes): StoredFile
    {
        $path = tempnam(sys_get_temp_dir(), 'foresi-document-');
        if (false === $path || false === file_put_contents($path, $bytes)) {
            throw DocumentsRefused::conflict('file_not_stored', 'The file of the document could not be stored. Try again.');
        }
        try {
            $attachment = $this->upload->upload(Document::ATTACHMENT_KIND, $documentId, new AttachmentUpload($path, $fileName, \strlen($bytes)));
        } catch (AttachmentRefused $refused) {
            throw DocumentsRefused::conflict('file_not_stored', \sprintf('The file of the document could not be stored (%s).', $refused->getMessage()));
        } finally {
            @unlink($path);
        }

        return new StoredFile($attachment->id(), $attachment->sha256());
    }
}
