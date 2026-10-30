<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Application;

use App\Tenancy\Domain\TenantId;

/** Keeps the uploaded source files out of the public web root until they are purged. */
interface ImportFiles
{
    /**
     * Copies the file into the tenant's storage. The original name is never part of the path.
     *
     * @return string the file key to give back to path() and delete()
     */
    public function store(TenantId $tenant, string $tmpPath, string $originalName): string;

    /**
     * @throws \RuntimeException when the key is malformed or the file is gone
     */
    public function path(string $fileKey): string;

    /** Removes the file; a file that is already gone is not an error. */
    public function delete(string $fileKey): void;
}
