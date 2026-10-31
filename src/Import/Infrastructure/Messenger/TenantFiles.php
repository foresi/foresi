<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Infrastructure\Messenger;

use App\Import\Application\ImportFiles;
use App\Import\Domain\Import;
use App\Tenancy\Application\TenantContext;

/**
 * The path of an import's file, only when its key lies in the active tenant's
 * storage: a worker never reads another tenant's file, whatever the row says.
 */
final readonly class TenantFiles
{
    public function __construct(private ImportFiles $files, private TenantContext $context)
    {
    }

    /**
     * @throws MissingImportFile
     */
    public function path(Import $import): string
    {
        $key = $import->fileKey();
        if (null === $key || !str_starts_with($key, $this->context->current()->toString().'/')) {
            throw new MissingImportFile('The import file does not belong to the active tenant.');
        }
        try {
            return $this->files->path($key);
        } catch (\RuntimeException $missing) {
            throw new MissingImportFile($missing->getMessage(), 0, $missing);
        }
    }
}
