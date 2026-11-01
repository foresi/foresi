<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Pdf;

/**
 * Sizes the columns of the items table to what a document prints, so that a value
 * never breaks inside a number or a unit: each column gets its longest value, the
 * text column the rest, and only a table that cannot fit that way gets a smaller type.
 */
final readonly class InvoiceColumns
{
    /** The width of the widest characters of Inter, in em; anything else counts as a letter. */
    private const array WIDTHS = [' ' => 0.29, "\u{a0}" => 0.29, ',' => 0.29, '.' => 0.29, '-' => 0.45, '%' => 0.86, 'm' => 0.89, 'M' => 0.9, 'W' => 1.0];
    private const float DIGIT_EM = 0.65;
    private const float LETTER_EM = 0.62;
    private const float POINT_MM = 0.3528;
    private const float SMALLEST_PT = 6.0;
    private const float STEP_PT = 0.5;
    private const float TEXT_MIN_MM = 34.0;

    /**
     * @param array<string, int>          $shares the least each column takes, as shares of the table
     * @param array<string, list<string>> $values what each column prints, its head included
     *
     * @return array{font: float, widths: array<string, string>} the type size in points and the width of each column, the text column too
     */
    public static function fit(array $shares, array $values, float $tableMm, float $paddingMm, float $fontPt): array
    {
        $floors = array_map(static fn (int $share): float => $tableMm * $share / 100, $shares);
        $sized = self::sized($floors, $values, $paddingMm, $fontPt);
        if ($tableMm - array_sum($sized) >= self::TEXT_MIN_MM) {
            return self::result($sized, $tableMm, $fontPt);
        }

        $none = array_map(static fn (float $floor): float => 0.0, $floors);
        for ($pt = $fontPt; $pt >= self::SMALLEST_PT; $pt -= self::STEP_PT) {
            $sized = self::sized($none, $values, $paddingMm, $pt);
            if ($tableMm - array_sum($sized) >= self::TEXT_MIN_MM) {
                return self::result($sized, $tableMm, $pt);
            }
        }

        return self::result($sized, $tableMm, self::SMALLEST_PT);
    }

    /**
     * @param array<string, float>        $floors
     * @param array<string, list<string>> $values
     *
     * @return array<string, float>
     */
    private static function sized(array $floors, array $values, float $paddingMm, float $pt): array
    {
        $sized = [];
        foreach ($floors as $column => $floor) {
            $longest = max([0.0, ...array_map(self::emOf(...), $values[$column] ?? [])]);
            $sized[$column] = max($floor, $longest * $pt * self::POINT_MM + $paddingMm);
        }

        return $sized;
    }

    private static function emOf(string $value): float
    {
        $em = 0.0;
        foreach (mb_str_split($value) as $character) {
            $em += self::WIDTHS[$character] ?? (ctype_digit($character) ? self::DIGIT_EM : self::LETTER_EM);
        }

        return $em;
    }

    /**
     * @param array<string, float> $sized
     *
     * @return array{font: float, widths: array<string, string>}
     */
    private static function result(array $sized, float $tableMm, float $pt): array
    {
        $widths = array_map(static fn (float $mm): string => \sprintf('%.2F%%', $mm / $tableMm * 100), $sized);
        $widths['text'] = \sprintf('%.2F%%', ($tableMm - array_sum($sized)) / $tableMm * 100);

        return ['font' => $pt, 'widths' => $widths];
    }
}
