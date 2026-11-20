<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\TaxesAndContributions\Support;

use Psr\Log\AbstractLogger;

/**
 * The records logged, as level and context.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{string, array<mixed>}> */
    public array $records = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = [\is_string($level) ? $level : 'other', $context];
    }
}
