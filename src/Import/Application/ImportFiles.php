<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Import\Application;

/** Keeps the uploaded source files out of the public web root until they are purged. */
interface ImportFiles
{
    /**
     * Copies the file into the store. The original name is never part of the path.
     *
     * @return string the file key to give back to path() and delete(); keys stored
     *                before one instance served one customer, "{tenant}/{id}", still read
     */
    public function store(string $tmpPath, string $originalName): string;

    /**
     * @throws \RuntimeException when the key is malformed or the file is gone
     */
    public function path(string $fileKey): string;

    /** Removes the file; a file that is already gone is not an error. */
    public function delete(string $fileKey): void;
}
