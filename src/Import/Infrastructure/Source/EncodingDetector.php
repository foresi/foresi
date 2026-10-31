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
    private const int CHUNK_BYTES = 1_048_576;
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

    /**
     * Detects the encoding of a whole file, streamed in chunks: UTF-8 validity
     * and the Windows-1250 letters are looked for everywhere, not only at the
     * start, so a file that turns non-ASCII late is not taken for UTF-8.
     */
    public function detectFile(string $path): Encoding
    {
        $handle = fopen($path, 'rb');
        if (false === $handle) {
            return Encoding::Utf8;
        }

        try {
            $carry = '';
            $valid = true;
            $windows1250 = false;
            $first = true;

            while (!feof($handle)) {
                $chunk = fread($handle, self::CHUNK_BYTES);
                if (false === $chunk || '' === $chunk) {
                    break;
                }
                if ($first && str_starts_with($chunk, self::BOM)) {
                    return Encoding::Utf8Bom;
                }
                $first = false;
                $windows1250 = $windows1250 || false !== strpbrk($chunk, self::WINDOWS_1250_BYTES);

                if ($valid) {
                    $carry = $this->validPrefixRest($carry.$chunk);
                    $valid = null !== $carry;
                    $carry ??= '';
                }
            }
        } finally {
            fclose($handle);
        }

        if ($valid && '' === $carry) {
            return Encoding::Utf8;
        }

        return $windows1250 ? Encoding::Windows1250 : Encoding::Windows1252;
    }

    /**
     * The bytes of a cut-off multibyte character at the end of valid UTF-8, or
     * null when the text is not valid UTF-8.
     */
    private function validPrefixRest(string $text): ?string
    {
        for ($cut = 0; $cut <= 3; ++$cut) {
            if (mb_check_encoding(0 === $cut ? $text : substr($text, 0, -$cut), 'UTF-8')) {
                return 0 === $cut ? '' : substr($text, -$cut);
            }
        }

        return null;
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
