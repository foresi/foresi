<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Idoklad\Infrastructure\Files;

use Foresi\Idoklad\Application\IdokladFiles;
use Foresi\Idoklad\Application\IdokladFileUnreadable;

/**
 * Pulled PDFs in one private directory, never below public/. Back the directory
 * up with the database while an import still needs them. Files pulled while an
 * instance had tenants lie one directory deeper, under the tenant's id.
 */
final readonly class DiskIdokladFiles implements IdokladFiles
{
    private const string ID = '/^[0-9a-f-]{36}$/D';
    private const string KEY = '/^(?:[0-9a-f-]{36}\/)?([0-9a-f-]{36})\.pdf$/D';

    public function __construct(private string $root)
    {
    }

    public function store(string $recordId, string $bytes): string
    {
        if (1 !== preg_match(self::ID, $recordId)) {
            throw new \InvalidArgumentException('A record is named by its id.');
        }
        $key = $recordId.'.pdf';
        if (!is_dir($this->root) && !mkdir($this->root, 0o700, true) && !is_dir($this->root)) {
            throw new \RuntimeException('The directory of iDoklad documents cannot be created.');
        }
        // A directory that already exists may have been created with wider permissions.
        chmod($this->root, 0o700);

        // Private from the start, so the content is never readable by others.
        $temporary = tempnam($this->root, 'idoklad');
        if (false === $temporary || false === file_put_contents($temporary, $bytes) || !chmod($temporary, 0o600) || !rename($temporary, $this->root.'/'.$key)) {
            throw new \RuntimeException('The iDoklad document cannot be stored.');
        }

        return $key;
    }

    public function read(string $recordId, string $key, string $sha256): string
    {
        if (1 !== preg_match(self::KEY, $key, $parts) || $parts[1] !== $recordId) {
            throw new IdokladFileUnreadable('The iDoklad document does not belong to the record.');
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
