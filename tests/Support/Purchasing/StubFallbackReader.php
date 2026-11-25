<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Support\Purchasing;

use Foresi\Purchasing\Contract\Reading\DocumentFile;
use Foresi\Purchasing\Contract\Reading\FallbackReader;
use Foresi\Purchasing\Contract\Reading\Reading;

/**
 * A reader that runs after the others only when they missed a key field.
 */
final readonly class StubFallbackReader implements FallbackReader
{
    /**
     * @param \Closure(DocumentFile): ?Reading $reader
     */
    public function __construct(private string $key, private \Closure $reader, private bool $takes, private bool $runs)
    {
    }

    public function source(): string
    {
        return $this->key;
    }

    public function supports(DocumentFile $file): bool
    {
        return $this->takes;
    }

    public function automatic(): bool
    {
        return $this->runs;
    }

    public function read(DocumentFile $file): ?Reading
    {
        return ($this->reader)($file);
    }
}
