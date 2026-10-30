<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Application\Source;

/**
 * How a source file is read. Every part except the format may be left null to
 * be detected from the file, so the same value serves as a user override and,
 * once resolved by SourceInspection::settings(), as the stored configuration.
 */
final readonly class SourceSettings
{
    /**
     * @param string|null $delimiter one character; CSV only
     * @param int|null    $headerRow 1-based source row of the header
     */
    public function __construct(
        public SourceFormat $format,
        public ?Encoding $encoding = null,
        public ?string $delimiter = null,
        public ?string $sheet = null,
        public ?int $headerRow = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data the output of toArray()
     */
    public static function fromArray(array $data): self
    {
        $format = $data['format'] ?? null;
        $encoding = $data['encoding'] ?? null;
        $delimiter = $data['delimiter'] ?? null;
        $sheet = $data['sheet'] ?? null;
        $headerRow = $data['headerRow'] ?? null;

        return new self(
            SourceFormat::from(\is_string($format) ? $format : ''),
            \is_string($encoding) ? Encoding::from($encoding) : null,
            \is_string($delimiter) ? $delimiter : null,
            \is_string($sheet) ? $sheet : null,
            \is_int($headerRow) ? $headerRow : null,
        );
    }

    /**
     * @return array{format: string, encoding: string|null, delimiter: string|null, sheet: string|null, headerRow: int|null}
     */
    public function toArray(): array
    {
        return [
            'format' => $this->format->value,
            'encoding' => $this->encoding?->value,
            'delimiter' => $this->delimiter,
            'sheet' => $this->sheet,
            'headerRow' => $this->headerRow,
        ];
    }
}
