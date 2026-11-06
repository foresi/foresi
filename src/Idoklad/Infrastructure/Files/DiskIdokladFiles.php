<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Idoklad\Infrastructure\Files;

use App\Idoklad\Application\IdokladFiles;
use App\Idoklad\Application\IdokladFileUnreadable;

/**
 * Pulled PDFs under one directory per tenant, never below public/. Back the
 * directory up with the database while an import still needs them.
 */
final readonly class DiskIdokladFiles implements IdokladFiles
{
    private const string KEY = '/^([0-9a-f-]{36})\/([0-9a-f-]{36})\.pdf$/D';

    public function __construct(private string $root)
    {
    }

    public function store(string $tenantId, string $recordId, string $bytes): string
    {
        $key = $tenantId.'/'.$recordId.'.pdf';
        if (1 !== preg_match(self::KEY, $key)) {
            throw new \InvalidArgumentException('A tenant and a record are named by their ids.');
        }
        $directory = $this->root.'/'.$tenantId;
        if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new \RuntimeException('The directory of iDoklad documents cannot be created.');
        }
        // A directory that already exists may have been created with wider permissions.
        chmod($directory, 0o700);

        // Private from the start, so the content is never readable by others.
        $temporary = tempnam($directory, 'idoklad');
        if (false === $temporary || false === file_put_contents($temporary, $bytes) || !chmod($temporary, 0o600) || !rename($temporary, $this->root.'/'.$key)) {
            throw new \RuntimeException('The iDoklad document cannot be stored.');
        }

        return $key;
    }

    public function read(string $tenantId, string $key, string $sha256): string
    {
        if (1 !== preg_match(self::KEY, $key, $parts) || $parts[1] !== $tenantId) {
            throw new IdokladFileUnreadable('The iDoklad document does not belong to the tenant.');
        }
        $path = $this->root.'/'.$key;
        $bytes = is_file($path) ? file_get_contents($path) : false;
        if (false === $bytes) {
            throw new IdokladFileUnreadable('The iDoklad document is missing.');
        }
        if (!hash_equals($sha256, hash('sha256', $bytes))) {
            throw new IdokladFileUnreadable('The iDoklad document changed after it was pulled.');
        }

        return $bytes;
    }
}
