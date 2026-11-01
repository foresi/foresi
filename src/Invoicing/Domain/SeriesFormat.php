<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Domain;

/**
 * How the numbers of a series look: text and the tokens {YYYY} {YY} {MM} {DD}
 * and exactly one {N...}, whose length is the minimum width of the sequence.
 * Text stays within the characters a number can carry without losing them in
 * NumberKey. The sequence is never truncated. A format with a year token counts
 * per year, any other in the one period "all".
 */
final readonly class SeriesFormat
{
    public const int MAX_LENGTH = 64;
    public const string ALL_PERIODS = 'all';
    /** The largest value of the counter column. */
    public const int MAX_SEQUENCE = 2_147_483_647;

    private const string TEXT = '/^[A-Za-z0-9._\/-]*$/D';
    private const string TOKEN = '/^\{(YYYY|YY|MM|DD|N+)\}$/D';
    private const array DATE_TOKENS = ['{YYYY}' => 'Y', '{YY}' => 'y', '{MM}' => 'm', '{DD}' => 'd'];
    private const int MAX_SEQUENCE_DIGITS = 10;

    /**
     * @param list<string> $parts text and tokens in order
     */
    private function __construct(private string $source, private array $parts)
    {
    }

    /**
     * @throws InvalidSeriesFormat
     */
    public static function fromString(string $format): self
    {
        if (\strlen($format) > self::MAX_LENGTH) {
            throw new InvalidSeriesFormat('The format is too long.');
        }

        // Tokens, text, and a brace of neither, which the check below refuses.
        preg_match_all('/\{[^{}]*\}|[^{}]+|[{}]/', $format, $matches);
        $parts = $matches[0];
        $sequences = 0;
        foreach ($parts as $part) {
            if (1 === preg_match(self::TOKEN, $part)) {
                $sequences += 'N' === $part[1] ? 1 : 0;
            } elseif (1 !== preg_match(self::TEXT, $part)) {
                throw new InvalidSeriesFormat('The format holds something other than its tokens.');
            }
        }
        if (1 !== $sequences) {
            throw new InvalidSeriesFormat('The format needs exactly one sequence token.');
        }

        return new self($format, $parts);
    }

    public function toString(): string
    {
        return $this->source;
    }

    public function render(\DateTimeImmutable $date, int $sequence): string
    {
        $number = '';
        foreach ($this->parts as $part) {
            $number .= match (true) {
                $this->isSequence($part) => str_pad((string) $sequence, \strlen($part) - 2, '0', \STR_PAD_LEFT),
                isset(self::DATE_TOKENS[$part]) => $date->format(self::DATE_TOKENS[$part]),
                default => $part,
            };
        }

        return $number;
    }

    /**
     * The sequence of a number of this series on that date. Numbers are the same
     * when their NumberKey is, so case and punctuation do not matter. Null when the
     * number does not have this form, or holds a sequence the counter cannot.
     */
    public function sequenceIn(string $number, \DateTimeImmutable $date): ?int
    {
        $pattern = '';
        foreach ($this->parts as $part) {
            $pattern .= match (true) {
                $this->isSequence($part) => '(\d{'.(\strlen($part) - 2).',})',
                isset(self::DATE_TOKENS[$part]) => $date->format(self::DATE_TOKENS[$part]),
                default => NumberKey::of($part),
            };
        }
        if (1 !== preg_match('/^'.$pattern.'$/D', NumberKey::of($number), $match)) {
            return null;
        }

        $digits = ltrim($match[1], '0');
        if (\strlen($digits) > self::MAX_SEQUENCE_DIGITS || (int) $digits > self::MAX_SEQUENCE) {
            return null;
        }

        return (int) $digits;
    }

    public function period(\DateTimeImmutable $date): string
    {
        return [] === array_intersect(['{YYYY}', '{YY}'], $this->parts) ? self::ALL_PERIODS : $date->format('Y');
    }

    /**
     * Whether numbers of both formats can be the same number of a firm. The width
     * of the sequence is only a minimum, so it keeps no numbers apart.
     */
    public function collidesWith(self $other): bool
    {
        return $this->shape() === $other->shape();
    }

    /**
     * The key form of the numbers, with one mark for the sequence of any width.
     */
    private function shape(): string
    {
        $date = new \DateTimeImmutable('2000-01-01');
        $shape = '';
        foreach ($this->parts as $part) {
            $shape .= $this->isSequence($part) ? '#' : NumberKey::of($this->datePart($part, $date));
        }

        return $shape;
    }

    private function datePart(string $part, \DateTimeImmutable $date): string
    {
        return isset(self::DATE_TOKENS[$part]) ? $date->format(self::DATE_TOKENS[$part]) : $part;
    }

    private function isSequence(string $part): bool
    {
        return str_starts_with($part, '{N');
    }
}
