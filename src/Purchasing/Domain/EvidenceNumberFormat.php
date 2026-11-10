<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Domain;

/**
 * How the evidence numbers of received documents look: text and the tokens
 * {YYYY} or {YY} and exactly one {N...}, whose length is the minimum width of
 * the sequence. A format with a year counts per year of the received date, any
 * other in the one period "all". The sequence is never truncated.
 *
 * A small format of its own: the format of the sales series lives in Invoicing,
 * which Purchasing does not depend on.
 */
final readonly class EvidenceNumberFormat
{
    public const int MAX_LENGTH = 32;
    public const string ALL_PERIODS = 'all';
    public const string STANDARD = 'R{YYYY}{NNNN}';
    /** The largest value of the counter column. */
    public const int MAX_SEQUENCE = 2_147_483_647;

    private const string TEXT = '/^[A-Za-z0-9._\/-]*$/D';
    private const string TOKEN = '/^\{(YYYY|YY|N{1,10})\}$/D';
    private const array YEAR_TOKENS = ['{YYYY}' => 'Y', '{YY}' => 'y'];

    /**
     * @param list<string> $parts text and tokens in order
     */
    private function __construct(private string $source, private array $parts)
    {
    }

    /**
     * @throws InvalidEvidenceFormat
     */
    public static function fromString(string $format): self
    {
        if (\strlen($format) > self::MAX_LENGTH) {
            throw new InvalidEvidenceFormat('The format is too long.');
        }
        // Tokens, text, and a brace of neither, which the check below refuses.
        preg_match_all('/\{[^{}]*\}|[^{}]+|[{}]/', $format, $matches);
        $parts = $matches[0];
        $sequences = 0;
        foreach ($parts as $part) {
            if (1 === preg_match(self::TOKEN, $part)) {
                $sequences += 'N' === $part[1] ? 1 : 0;
            } elseif (1 !== preg_match(self::TEXT, $part)) {
                throw new InvalidEvidenceFormat('The format holds something other than its tokens.');
            }
        }
        if (1 !== $sequences) {
            throw new InvalidEvidenceFormat('The format needs exactly one sequence token.');
        }

        return new self($format, $parts);
    }

    public static function standard(): self
    {
        return self::fromString(self::STANDARD);
    }

    public function toString(): string
    {
        return $this->source;
    }

    public function render(\DateTimeImmutable $date, int $sequence): string
    {
        if ($sequence < 1 || $sequence > self::MAX_SEQUENCE) {
            throw new \RangeException('A sequence is positive and fits the counter.');
        }
        $number = '';
        foreach ($this->parts as $part) {
            $number .= match (true) {
                isset(self::YEAR_TOKENS[$part]) => $date->format(self::YEAR_TOKENS[$part]),
                1 === preg_match(self::TOKEN, $part) => str_pad((string) $sequence, \strlen($part) - 2, '0', \STR_PAD_LEFT),
                default => $part,
            };
        }

        return $number;
    }

    /**
     * The period the counter counts in for a date: its year, or "all".
     */
    public function period(\DateTimeImmutable $date): string
    {
        return [] === array_intersect(array_keys(self::YEAR_TOKENS), $this->parts) ? self::ALL_PERIODS : $date->format('Y');
    }

    /**
     * The longest number this format can give, whatever the date and the counter.
     */
    public function longestNumber(): string
    {
        return $this->render(new \DateTimeImmutable('2000-12-31'), self::MAX_SEQUENCE);
    }
}
