<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Logging;

use Monolog\Formatter\FormatterInterface;
use Monolog\LogRecord;

/**
 * The plain-text body of an operator's error mail: one block per record with its
 * time, level, channel and message, the context as JSON and an exception as its
 * class, message and place. Every text is scrubbed again, so a record that did
 * not pass the processor (a handler used on its own) leaks nothing either.
 */
final readonly class OperatorMailFormatter implements FormatterInterface
{
    public function format(LogRecord $record): string
    {
        $record = (new RedactSecrets())($record);
        $lines = [\sprintf('%s %s %s: %s', $record->datetime->format('Y-m-d H:i:s T'), $record->level->getName(), $record->channel, $record->message)];
        $context = $record->context;
        $exception = $context['exception'] ?? null;
        unset($context['exception']);
        while ($exception instanceof \Throwable) {
            $lines[] = \sprintf('  %s: %s at %s:%d', $exception::class, RedactSecrets::scrub($exception->getMessage()), $exception->getFile(), $exception->getLine());
            $exception = $exception->getPrevious();
        }
        if ([] !== $context) {
            $lines[] = '  '.json_encode($context, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR);
        }

        return implode("\n", $lines)."\n";
    }

    public function formatBatch(array $records): string
    {
        return implode("\n", array_map($this->format(...), $records));
    }
}
