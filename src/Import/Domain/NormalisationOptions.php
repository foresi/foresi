<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Domain;

/** How the dates and decimal numbers of one source are written. */
final readonly class NormalisationOptions
{
    /**
     * @param bool $ambiguousDateOrder true when the samples read as a date in more than one order
     * @param bool $ambiguousDecimal   true when the samples read with either separator as the decimal one (for example "1,234")
     */
    public function __construct(
        public DateOrder $dateOrder = DateOrder::DayMonthYear,
        public DecimalSeparator $decimalSeparator = DecimalSeparator::Comma,
        public bool $ambiguousDateOrder = false,
        public bool $ambiguousDecimal = false,
    ) {
    }

    /**
     * Proposes the options that read every sample; the user can still change them.
     *
     * @param list<string> $samples cell texts of the mapped date and decimal columns
     */
    public static function detect(array $samples): self
    {
        $samples = array_values(array_filter(array_map(Text::clean(...), $samples), static fn (string $sample): bool => '' !== $sample));

        $dates = self::detectDates($samples);
        $decimals = self::detectSeparator($samples);

        return new self($dates['dateOrder'], $decimals['decimalSeparator'], $dates['ambiguousDateOrder'], $decimals['ambiguousDecimal']);
    }

    /**
     * @param list<string> $samples
     *
     * @return array{dateOrder: DateOrder, ambiguousDateOrder: bool}
     */
    private static function detectDates(array $samples): array
    {
        $dates = array_values(array_filter($samples, self::looksLikeDate(...)));
        if ([] === $dates || self::readsAll($dates, DateOrder::YearMonthDay)) {
            return ['dateOrder' => [] === $dates ? DateOrder::DayMonthYear : DateOrder::YearMonthDay, 'ambiguousDateOrder' => false];
        }
        $dayFirst = self::readsAll($dates, DateOrder::DayMonthYear);
        $monthFirst = self::readsAll($dates, DateOrder::MonthDayYear);

        return [
            'dateOrder' => $monthFirst && !$dayFirst ? DateOrder::MonthDayYear : DateOrder::DayMonthYear,
            'ambiguousDateOrder' => $dayFirst && $monthFirst,
        ];
    }

    /**
     * @param list<string> $dates
     */
    private static function readsAll(array $dates, DateOrder $order): bool
    {
        return array_all($dates, fn (string $date): bool => DateParser::parse($date, $order) instanceof \DateTimeImmutable);
    }

    /**
     * A sample with both separators, or with one that cannot be grouping, decides
     * the separator. A single separator followed by exactly three digits could be
     * either, so such a sample is ambiguous on its own; the choice is then
     * ambiguous when no other sample decides.
     *
     * @param list<string> $samples
     *
     * @return array{decimalSeparator: DecimalSeparator, ambiguousDecimal: bool}
     */
    private static function detectSeparator(array $samples): array
    {
        $points = 0;
        $commas = 0;
        $ambiguous = 0;
        foreach ($samples as $sample) {
            $number = Text::squash($sample);
            if (self::looksLikeDate($sample) || 1 !== preg_match('/^[+-]?\d[\d.,]*$/', $number)) {
                continue;
            }
            $lastPoint = strrpos($number, '.');
            $lastComma = strrpos($number, ',');
            if (false !== $lastPoint && false !== $lastComma) {
                $lastPoint > $lastComma ? ++$points : ++$commas;
            } elseif (1 === preg_match('/^[+-]?[1-9]\d{0,2}[.,]\d{3}$/', $number)) {
                ++$ambiguous;
            } elseif (1 === substr_count($number, '.')) {
                ++$points;
            } elseif (1 === substr_count($number, ',')) {
                ++$commas;
            }
        }

        return [
            'decimalSeparator' => $points > $commas ? DecimalSeparator::Point : DecimalSeparator::Comma,
            'ambiguousDecimal' => $ambiguous > 0 && 0 === $points + $commas,
        ];
    }

    private static function looksLikeDate(string $sample): bool
    {
        return 1 === preg_match('/^\d{1,4}\s*[.\/-]\s*\d{1,2}\s*[.\/-]\s*\d{1,4}/', $sample);
    }
}
