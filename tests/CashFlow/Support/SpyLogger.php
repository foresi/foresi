<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\CashFlow\Support;

use Psr\Log\AbstractLogger;

/**
 * Keeps what was logged, oldest first.
 */
final class SpyLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<mixed>}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        \assert(\is_string($level));
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}
