<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Infrastructure\Files;

use App\Banking\Application\InboundFiles;

/**
 * Raw inbound mail under one directory per tenant, never below public/, each
 * file readable by the server's user only. Back the directory up with the
 * database.
 */
final readonly class DiskInboundFiles implements InboundFiles
{
    private const string KEY = '/^([0-9a-f-]{36})\/([0-9a-f-]{36})\.eml$/D';

    public function __construct(private string $root)
    {
    }

    public function store(string $tenantId, string $messageId, string $raw): string
    {
        $key = $this->key($tenantId, $messageId);
        $directory = $this->root.'/'.$tenantId;
        if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new \RuntimeException('The directory of inbound mail cannot be created.');
        }
        // A directory that already exists may have been created with wider permissions.
        chmod($directory, 0o700);

        // Private from the start, so the content is never readable by others.
        $temporary = tempnam($directory, 'mail');
        if (false === $temporary || false === file_put_contents($temporary, $raw) || !chmod($temporary, 0o600) || !rename($temporary, $this->root.'/'.$key)) {
            if (\is_string($temporary) && is_file($temporary)) {
                unlink($temporary);
            }

            throw new \RuntimeException('The inbound mail cannot be stored.');
        }

        return $key;
    }

    public function read(string $tenantId, string $key, string $sha256): string
    {
        if (1 !== preg_match(self::KEY, $key, $parts) || $parts[1] !== $tenantId) {
            throw new \RuntimeException('The inbound mail does not belong to the tenant.');
        }
        $path = $this->root.'/'.$key;
        $bytes = is_file($path) ? file_get_contents($path) : false;
        if (false === $bytes) {
            throw new \RuntimeException('The inbound mail is missing.');
        }
        if (!hash_equals($sha256, hash('sha256', $bytes))) {
            throw new \RuntimeException('The inbound mail changed after it was stored.');
        }

        return $bytes;
    }

    public function remove(string $tenantId, string $messageId): void
    {
        $path = $this->root.'/'.$this->key($tenantId, $messageId);
        if (is_file($path)) {
            unlink($path);
        }
    }

    private function key(string $tenantId, string $messageId): string
    {
        $key = $tenantId.'/'.$messageId.'.eml';
        if (1 !== preg_match(self::KEY, $key)) {
            throw new \InvalidArgumentException('A tenant and a message are named by their ids.');
        }

        return $key;
    }
}
