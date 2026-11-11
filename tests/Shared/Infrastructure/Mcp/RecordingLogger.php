<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Mcp;

use Psr\Log\AbstractLogger;

/**
 * Keeps every record it is given.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{mixed, string|\Stringable, array<mixed>}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [$level, $message, $context];
    }
}
