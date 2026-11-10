<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Domain;

/**
 * A length of work in whole minutes, as a person types it (Kimai): "1:30" is
 * hours and minutes, a bare number is hours ("2", "1,5" and "1.5"), and units
 * may be spelled out ("1h 30m", "45m", "2h", "1.5h"). A fraction of an hour is
 * rounded to the nearest minute. Nothing, a negative length and more than the
 * limit, a day unless said otherwise, are refused.
 */
final readonly class Duration
{
    /** A day: no one works more on one entry. */
    public const int MAX_MINUTES = 24 * 60;

    private function __construct(public int $minutes)
    {
    }

    /**
     * @throws \DomainException when the text is no duration, nothing, or longer than the limit
     */
    public static function parse(string $text, int $max = self::MAX_MINUTES): self
    {
        $text = mb_strtolower(trim(str_replace("\u{00A0}", ' ', $text)));
        $minutes = match (true) {
            1 === preg_match('/^(\d{1,4}):([0-5]\d)$/', $text, $clock) => (int) $clock[1] * 60 + (int) $clock[2],
            1 === preg_match('/^\d{1,4}(?:[.,]\d+)?$/', $text) => self::hours($text),
            1 === preg_match('/^(?=.)(?:(\d{1,4}(?:[.,]\d+)?)\s*h)?\s*(?:(\d{1,5})\s*m(?:in)?)?$/', $text, $units) => self::hours($units[1] ?? '') + (int) ($units[2] ?? 0),
            default => throw new \DomainException(sprintf('"%s" is no duration.', $text)),
        };

        return self::ofMinutes($minutes, $max);
    }

    /**
     * @throws \DomainException when it is nothing or longer than the limit
     */
    public static function ofMinutes(int $minutes, int $max = self::MAX_MINUTES): self
    {
        if ($minutes < 1) {
            throw new \DomainException('A duration is at least a minute.');
        }
        if ($minutes > $max) {
            throw new \DomainException(sprintf('A duration is at most %d minutes.', $max));
        }

        return new self($minutes);
    }

    /**
     * The minutes from the start to the end, rounded to the nearest one and at
     * least one.
     *
     * @throws \DomainException when the end is not after the start, or the span is longer than the limit
     */
    public static function between(\DateTimeImmutable $start, \DateTimeImmutable $end, int $max = self::MAX_MINUTES): self
    {
        if ($end <= $start) {
            throw new \DomainException('The end of work comes after its start.');
        }

        return self::ofMinutes(self::spanMinutes($start, $end), $max);
    }

    /**
     * Whole minutes from the start to the end, rounded, never less than one:
     * what a stopped timer counts, however short or long it ran.
     */
    public static function spanMinutes(\DateTimeImmutable $start, \DateTimeImmutable $end): int
    {
        return max(1, (int) round(($end->getTimestamp() - $start->getTimestamp()) / 60));
    }

    /**
     * Hours with a decimal comma or point, as minutes; nothing for an empty text.
     */
    private static function hours(string $hours): int
    {
        return '' === $hours ? 0 : (int) round((float) str_replace(',', '.', $hours) * 60);
    }
}
