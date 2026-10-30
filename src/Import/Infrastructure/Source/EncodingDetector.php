<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Infrastructure\Source;

use App\Import\Application\Source\Encoding;

/**
 * Tells the encoding of a CSV sample: a byte order mark, valid UTF-8, or one
 * of the single-byte encodings Czech accounting tools export.
 */
final readonly class EncodingDetector
{
    private const string BOM = "\xEF\xBB\xBF";

    /**
     * Bytes of Czech letters in Windows-1250 (S, Z, s, z, c, r, e and u with a
     * caron or ring) that are no letters in Windows-1252.
     */
    private const string WINDOWS_1250_BYTES = "\x8A\x8E\x9A\x9E\xE8\xF8\xEC\xF9";

    /**
     * @param bool $truncated the sample is only the beginning of a longer file, so a character cut off at its end is no evidence against UTF-8
     */
    public function detect(string $sample, bool $truncated): Encoding
    {
        if (str_starts_with($sample, self::BOM)) {
            return Encoding::Utf8Bom;
        }

        if ($this->isUtf8($sample, $truncated)) {
            return Encoding::Utf8;
        }

        return false !== strpbrk($sample, self::WINDOWS_1250_BYTES) ? Encoding::Windows1250 : Encoding::Windows1252;
    }

    private function isUtf8(string $sample, bool $truncated): bool
    {
        if (mb_check_encoding($sample, 'UTF-8')) {
            return true;
        }

        if (!$truncated) {
            return false;
        }

        // A multibyte character is at most four bytes, so at most three may dangle.
        for ($cut = 1; $cut <= 3; ++$cut) {
            if (mb_check_encoding(substr($sample, 0, -$cut), 'UTF-8')) {
                return true;
            }
        }

        return false;
    }
}
