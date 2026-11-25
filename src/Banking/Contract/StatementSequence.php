<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Contract;

/**
 * The number a bank gives a statement: a number within a year (the statement
 * number of MT940 :28C:, camt.053 LglSeqNb, ABO/GPC), or an electronic sequence
 * that runs on (camt.053 ElctrncSeqNb). Two numbers of one kind follow on when the
 * later is the earlier plus one; a number within a year starts at 1 in a new year.
 */
final readonly class StatementSequence
{
    public const string WITHIN_YEAR = 'within_year';
    public const string ELECTRONIC = 'electronic';

    private function __construct(public string $kind, public int $number, public ?int $year)
    {
        if ($number < 1) {
            throw new \InvalidArgumentException('A statement number starts at 1.');
        }
        if (null !== $year && ($year < 1900 || $year > 9999)) {
            throw new \InvalidArgumentException('A statement year has four digits.');
        }
    }

    public static function withinYear(int $number, int $year): self
    {
        return new self(self::WITHIN_YEAR, $number, $year);
    }

    public static function electronic(int $number): self
    {
        return new self(self::ELECTRONIC, $number, null);
    }

    /**
     * @param string $kind one of the constants
     *
     * @throws \InvalidArgumentException for an unknown kind, or a year missing or given where it does not belong
     */
    public static function of(string $kind, int $number, ?int $year): self
    {
        return match (true) {
            self::WITHIN_YEAR === $kind && null !== $year => self::withinYear($number, $year),
            self::ELECTRONIC === $kind && null === $year => self::electronic($number),
            default => throw new \InvalidArgumentException(\sprintf('"%s" with this year is not a statement sequence.', $kind)),
        };
    }

    /**
     * Whether this statement follows on the earlier one: true when its number is the
     * next one, false when numbers are missing between them, and null when the two
     * cannot be compared: another kind, or this one is not later.
     */
    public function followsOn(self $earlier): ?bool
    {
        if ($this->kind !== $earlier->kind) {
            return null;
        }
        if (self::ELECTRONIC === $this->kind || $this->year === $earlier->year) {
            return $this->number > $earlier->number ? $this->number === $earlier->number + 1 : null;
        }
        if (null === $this->year || null === $earlier->year || $this->year < $earlier->year) {
            return null;
        }

        // A new year restarts at 1; a year in between missing is a gap of its own.
        return $this->year === $earlier->year + 1 && 1 === $this->number;
    }

    /**
     * The number as people write it: "42/2026", or "42".
     */
    public function label(): string
    {
        return null === $this->year ? (string) $this->number : $this->number.'/'.$this->year;
    }
}
