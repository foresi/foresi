<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Infrastructure;

use App\Import\Application\ImportFiles;
use App\Tenancy\Domain\TenantId;
use Symfony\Component\Uid\Uuid;

/**
 * Stores source files below one directory, outside the web root, as
 * <root>/<tenant>/<uuid> with mode 0600 (directories 0700).
 */
final readonly class LocalImportFiles implements ImportFiles
{
    private const string KEY_PATTERN = '/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}\/[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/D';

    public function __construct(private string $root)
    {
    }

    public function store(TenantId $tenant, string $tmpPath, string $originalName): string
    {
        $directory = $this->root.'/'.$tenant->toString();
        if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new \RuntimeException('The import directory cannot be created.');
        }
        // A directory that already exists may have been created with wider permissions.
        chmod($directory, 0o700);

        $key = $tenant->toString().'/'.Uuid::v4()->toRfc4122();
        $target = $this->root.'/'.$key;
        // Create the file private first, so the content is never readable by others.
        if (false === file_put_contents($target, '') || !chmod($target, 0o600)) {
            throw new \RuntimeException('The import file cannot be created.');
        }
        if (!is_readable($tmpPath) || !copy($tmpPath, $target)) {
            @unlink($target);

            throw new \RuntimeException('The import file cannot be stored.');
        }

        return $key;
    }

    public function path(string $fileKey): string
    {
        $path = $this->resolve($fileKey);
        if (!is_file($path)) {
            throw new \RuntimeException('The import file does not exist.');
        }

        return $path;
    }

    public function delete(string $fileKey): void
    {
        $path = $this->resolve($fileKey);
        if (is_file($path)) {
            unlink($path);
        }
    }

    private function resolve(string $fileKey): string
    {
        if (1 !== preg_match(self::KEY_PATTERN, $fileKey)) {
            throw new \RuntimeException('The import file key is malformed.');
        }

        return $this->root.'/'.$fileKey;
    }
}
