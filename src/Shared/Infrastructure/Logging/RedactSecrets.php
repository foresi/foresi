<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Logging;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Keeps secrets and personal data out of the log files and the operator's error
 * mail. A context or extra value under a sensitive key is blanked at any depth;
 * every text loses the token of a public document link (/d/{token}), its URL
 * query strings and its e-mail addresses.
 */
#[AsMonologProcessor]
final readonly class RedactSecrets implements ProcessorInterface
{
    private const string REDACTED = '[redacted]';

    /** Matched anywhere in a key, ignoring case, underscores and dashes. */
    private const array SENSITIVE_KEYS = ['password', 'passwd', 'secret', 'token', 'authorization', 'cookie', 'iban', 'email', 'body', 'apikey'];

    private const array TEXT_PATTERNS = [
        '~/d/[A-Za-z0-9_\-]+~' => '/d/'.self::REDACTED,
        '~(?<=[\w/])\?[^\s"\'<>]*=[^\s"\'<>]*~' => '?'.self::REDACTED,
        '~[^\s@<>"\'(),;:]+@[^\s@<>"\'(),;:]+\.[A-Za-z]{2,}~' => '[email]',
    ];

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: self::scrub($record->message),
            context: $this->redact($record->context),
            extra: $this->redact($record->extra),
        );
    }

    /**
     * The text without public link tokens, query strings and e-mail addresses.
     */
    public static function scrub(string $text): string
    {
        return preg_replace(array_keys(self::TEXT_PATTERNS), array_values(self::TEXT_PATTERNS), $text) ?? self::REDACTED;
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array<array-key, mixed>
     */
    private function redact(array $values): array
    {
        foreach ($values as $key => $value) {
            if (\is_string($key) && $this->isSensitive($key)) {
                $values[$key] = self::REDACTED;
            } elseif (\is_array($value)) {
                $values[$key] = $this->redact($value);
            } elseif (\is_string($value)) {
                $values[$key] = self::scrub($value);
            }
        }

        return $values;
    }

    private function isSensitive(string $key): bool
    {
        $key = strtolower(str_replace(['_', '-'], '', $key));

        return array_any(self::SENSITIVE_KEYS, fn (string $sensitive): bool => str_contains($key, $sensitive));
    }
}
