<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Support\Registry;

use Psr\Log\AbstractLogger;

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string}> */
    public array $records = [];

    /** @var list<array<mixed>> the context of each record, apart, so a check of what a message reveals stays on the message */
    public array $contexts = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => \is_string($level) ? $level : '', 'message' => (string) $message];
        $this->contexts[] = $context;
    }

    public function warnings(): int
    {
        return \count(array_filter($this->records, static fn (array $r): bool => 'warning' === $r['level']));
    }
}
