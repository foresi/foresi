<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Mail;

use Foresi\Invoicing\Application\SentFiles;
use Foresi\Invoicing\Application\SentFileUnreadable;

/**
 * Sent PDFs in one directory, never below public/. Back the directory up with
 * the database: a send without its file shows no document. PDFs stored while an
 * instance had tenants lie one directory deeper, under the tenant's id.
 */
final readonly class DiskSentFiles implements SentFiles
{
    private const string ID = '/^[0-9a-f-]{36}$/D';
    private const string KEY = '/^(?:[0-9a-f-]{36}\/)?([0-9a-f-]{36})\.pdf$/D';

    public function __construct(private string $root)
    {
    }

    public function store(string $sendId, string $bytes): string
    {
        $key = $this->key($sendId);
        $directory = $this->root;
        if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new \RuntimeException('The directory of sent documents cannot be created.');
        }
        // A directory that already exists may have been created with wider permissions.
        chmod($directory, 0o700);

        // Private from the start, so the content is never readable by others.
        $temporary = tempnam($directory, 'send');
        if (false === $temporary || false === file_put_contents($temporary, $bytes) || !chmod($temporary, 0o600) || !rename($temporary, $this->root.'/'.$key)) {
            throw new \RuntimeException('The sent document cannot be stored.');
        }

        return $key;
    }

    public function read(string $sendId, string $key, string $sha256): string
    {
        if (1 !== preg_match(self::KEY, $key, $parts) || $parts[1] !== $sendId) {
            throw new SentFileUnreadable('The sent document does not belong to the send.');
        }
        $path = $this->root.'/'.$key;
        $bytes = is_file($path) ? file_get_contents($path) : false;
        if (false === $bytes) {
            throw new SentFileUnreadable('The sent document is missing.');
        }
        if (!hash_equals($sha256, hash('sha256', $bytes))) {
            throw new SentFileUnreadable('The sent document changed after it was sent.');
        }

        return $bytes;
    }

    public function remove(string $sendId): void
    {
        $path = $this->root.'/'.$this->key($sendId);
        if (is_file($path)) {
            unlink($path);
        }
    }

    private function key(string $sendId): string
    {
        if (1 !== preg_match(self::ID, $sendId)) {
            throw new \InvalidArgumentException('A send is named by its id.');
        }

        return $sendId.'.pdf';
    }
}
