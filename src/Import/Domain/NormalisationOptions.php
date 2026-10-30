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
     * @param bool $ambiguous true when the samples read as a date in more than one order
     */
    public function __construct(
        public DateOrder $dateOrder = DateOrder::DayMonthYear,
        public DecimalSeparator $decimalSeparator = DecimalSeparator::Comma,
        public bool $ambiguous = false,
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

        return new self(...self::detectDates($samples), decimalSeparator: self::detectSeparator($samples));
    }

    /**
     * @param list<string> $samples
     *
     * @return array{dateOrder: DateOrder, ambiguous: bool}
     */
    private static function detectDates(array $samples): array
    {
        $dates = array_values(array_filter($samples, self::looksLikeDate(...)));
        if ([] === $dates || self::readsAll($dates, DateOrder::YearMonthDay)) {
            return ['dateOrder' => [] === $dates ? DateOrder::DayMonthYear : DateOrder::YearMonthDay, 'ambiguous' => false];
        }
        $dayFirst = self::readsAll($dates, DateOrder::DayMonthYear);
        $monthFirst = self::readsAll($dates, DateOrder::MonthDayYear);

        return [
            'dateOrder' => $monthFirst && !$dayFirst ? DateOrder::MonthDayYear : DateOrder::DayMonthYear,
            'ambiguous' => $dayFirst && $monthFirst,
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
     * @param list<string> $samples
     */
    private static function detectSeparator(array $samples): DecimalSeparator
    {
        $points = 0;
        $commas = 0;
        foreach ($samples as $sample) {
            $number = preg_replace('/\s+/u', '', $sample) ?? '';
            if (self::looksLikeDate($sample) || 1 !== preg_match('/^[+-]?\d[\d.,]*$/', $number)) {
                continue;
            }
            $lastPoint = strrpos($number, '.');
            $lastComma = strrpos($number, ',');
            if (false !== $lastPoint && false !== $lastComma) {
                $lastPoint > $lastComma ? ++$points : ++$commas;
            } elseif (false !== $lastPoint && 1 === substr_count($number, '.') && 3 !== \strlen($number) - $lastPoint - 1) {
                ++$points;
            } elseif (false !== $lastComma && 1 === substr_count($number, ',') && 3 !== \strlen($number) - $lastComma - 1) {
                ++$commas;
            }
        }

        return $points > $commas ? DecimalSeparator::Point : DecimalSeparator::Comma;
    }

    private static function looksLikeDate(string $sample): bool
    {
        return 1 === preg_match('/^\d{1,4}\s*[.\/-]\s*\d{1,2}\s*[.\/-]\s*\d{1,4}/', $sample);
    }
}
