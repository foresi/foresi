<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Application\Source;

/**
 * A source file that must not be read. The reason is a translation key under
 * "import.source.rejected" and the parameters fill its placeholders, so the
 * presentation layer can explain it in the user's language.
 */
final class SourceRejected extends \RuntimeException
{
    /**
     * @param array<string, int|string> $parameters
     */
    private function __construct(
        public readonly string $reason,
        public readonly array $parameters = [],
    ) {
        parent::__construct($reason);
    }

    public static function tooLarge(int $limitBytes): self
    {
        return new self('import.source.rejected.too_large', ['limit' => (int) ($limitBytes / 1_048_576)]);
    }

    public static function tooManyRows(int $limitRows): self
    {
        return new self('import.source.rejected.too_many_rows', ['limit' => $limitRows]);
    }

    public static function archiveTooLarge(int $limitBytes): self
    {
        return new self('import.source.rejected.archive_too_large', ['limit' => (int) ($limitBytes / 1_048_576)]);
    }

    public static function suspiciousCompression(int $limitRatio): self
    {
        return new self('import.source.rejected.suspicious_compression', ['limit' => $limitRatio]);
    }

    public static function unknownFormat(): self
    {
        return new self('import.source.rejected.unknown_format');
    }

    public static function empty(): self
    {
        return new self('import.source.rejected.empty');
    }
}
