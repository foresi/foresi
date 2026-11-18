<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Import;

use Foresi\Import\Application\Message\ArchiveMissing;
use Foresi\Import\Application\Message\CheckImportChunk;
use Foresi\Import\Application\Message\ReadImportFile;
use Foresi\Import\Application\Message\RunImportChunk;
use Foresi\Import\Application\Message\UndoImportChunk;
use Foresi\Shared\Infrastructure\Module\ModuleBundle;

/**
 * Import runs and their records; other modules plug in their targets.
 */
final class ImportBundle extends ModuleBundle
{
    protected function messageRouting(): array
    {
        // The import pipeline: one message per chunk, so a crash loses one chunk, not the import.
        return [
            ReadImportFile::class => 'async',
            CheckImportChunk::class => 'async',
            RunImportChunk::class => 'async',
            ArchiveMissing::class => 'async',
            UndoImportChunk::class => 'async',
        ];
    }
}
