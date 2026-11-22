<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Application;

/**
 * The files of a document, kept as attachments of kind "document".
 */
interface DocumentFiles
{
    /**
     * @throws DocumentsRefused when the file cannot be stored
     */
    public function store(string $documentId, string $fileName, string $bytes): StoredFile;
}
