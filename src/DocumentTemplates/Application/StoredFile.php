<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\DocumentTemplates\Application;

final readonly class StoredFile
{
    public function __construct(public string $attachmentId, public string $sha256)
    {
    }
}
