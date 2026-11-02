<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Banking\Support;

use App\Banking\Application\InboundFiles;

/**
 * The inbound file store of the tests: the real one, which a test can tell to
 * fail for any message holding a marker, as a full disk would. The marker is
 * static, as the store is a service of the container and the test sets it.
 */
final class FailingInboundFiles implements InboundFiles
{
    private static ?string $marker = null;

    public function __construct(private readonly InboundFiles $files)
    {
    }

    public static function failOn(?string $marker): void
    {
        self::$marker = $marker;
    }

    public function store(string $tenantId, string $messageId, string $raw): string
    {
        if (null !== self::$marker && str_contains($raw, self::$marker)) {
            throw new \RuntimeException('The disk is full.');
        }

        return $this->files->store($tenantId, $messageId, $raw);
    }

    public function read(string $tenantId, string $key, string $sha256): string
    {
        return $this->files->read($tenantId, $key, $sha256);
    }

    public function remove(string $tenantId, string $messageId): void
    {
        $this->files->remove($tenantId, $messageId);
    }
}
