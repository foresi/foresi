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

    private const string TEXT = '/^[A-Za-z0-9._\/-]*$/D';
    private const string TOKEN = '/^\{(YYYY|YY|MM|DD|N+)\}$/D';
    private const array DATE_TOKENS = ['{YYYY}' => 'Y', '{YY}' => 'y', '{MM}' => 'm', '{DD}' => 'd'];
    private const int MAX_SEQUENCE_DIGITS = 18;

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
     * The sequence of a number of this series on that date, in any case; null
     * when the number does not have this form.
     */
    public function sequenceIn(string $number, \DateTimeImmutable $date): ?int
    {
        $pattern = '';
        foreach ($this->parts as $part) {
            $pattern .= match (true) {
                $this->isSequence($part) => '(\d{'.(\strlen($part) - 2).',})',
                isset(self::DATE_TOKENS[$part]) => $date->format(self::DATE_TOKENS[$part]),
                default => preg_quote($part, '/'),
            };
        }
        if (1 !== preg_match('/^'.$pattern.'$/Di', $number, $match) || \strlen($match[1]) > self::MAX_SEQUENCE_DIGITS) {
            return null;
        }

        return (int) $match[1];
    }

    public function period(\DateTimeImmutable $date): string
    {
        return [] === array_intersect(['{YYYY}', '{YY}'], $this->parts) ? self::ALL_PERIODS : $date->format('Y');
    }

    /**
     * Whether numbers of both formats can be the same number of a firm.
     */
    public function collidesWith(self $other): bool
    {
        $date = new \DateTimeImmutable('2000-01-01');

        return NumberKey::of($this->render($date, 1)) === NumberKey::of($other->render($date, 1));
    }

    private function isSequence(string $part): bool
    {
        return str_starts_with($part, '{N');
    }
}
