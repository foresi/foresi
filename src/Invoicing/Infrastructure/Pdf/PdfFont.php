<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Pdf;

use App\Invoicing\Application\PrintableText;

/**
 * What the bundled font holds and how wide it sets a text. A document prints in
 * both faces, so a character counts only when both have it, and a width is that of
 * the bold face, the wider one. The definitions are read once, when first needed.
 */
final class PdfFont implements PrintableText
{
    private const array FACES = ['intertnum.json', 'intertnumb.json'];
    private const float MM_PER_POINT = 0.3528;
    private const array LINE_BREAKS = ["\n", "\r", "\t"];

    /** @var array<int, int>|null width in thousandths of the type size, by code point */
    private ?array $widths = null;

    public function __construct(private readonly string $directory = __DIR__.'/../../../../resources/pdf-fonts')
    {
    }

    public function unprintable(string $text): array
    {
        $widths = $this->widths();
        $missing = [];
        foreach (mb_str_split($text) as $character) {
            if (!\in_array($character, self::LINE_BREAKS, true) && !isset($widths[mb_ord($character)])) {
                $missing[$character] = true;
            }
        }

        return array_map(strval(...), array_keys($missing));
    }

    /**
     * @param float $size in points
     *
     * @return float millimetres
     */
    public function longestWord(string $text, float $size): float
    {
        $widest = 0.0;
        $words = preg_split('/[ \n\r\t]+/u', $text);
        foreach (false === $words ? [] : $words as $word) {
            $widest = max($widest, $this->width($word, $size));
        }

        return $widest;
    }

    /**
     * @param float $size in points
     *
     * @return float millimetres
     */
    public function width(string $word, float $size): float
    {
        $widths = $this->widths();
        $sum = 0;
        foreach (mb_str_split($word) as $character) {
            $sum += $widths[mb_ord($character)] ?? 1000;
        }

        return $sum * $size * self::MM_PER_POINT / 1000;
    }

    /**
     * @return array<int, int>
     */
    private function widths(): array
    {
        if (null !== $this->widths) {
            return $this->widths;
        }
        $faces = [];
        foreach (self::FACES as $file) {
            /** @var array{cw: array<int|string, int>} $face */
            $face = json_decode((string) file_get_contents($this->directory.'/'.$file), true, flags: \JSON_THROW_ON_ERROR);
            $faces[] = $face['cw'];
        }
        $widths = [];
        foreach ($faces[0] as $point => $width) {
            if (isset($faces[1][$point])) {
                $widths[(int) $point] = max($width, $faces[1][$point]);
            }
        }

        return $this->widths = $widths;
    }
}
