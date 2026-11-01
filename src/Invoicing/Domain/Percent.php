<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Domain;

/**
 * A percentage from 0 to 100 with two decimals, kept as whole basis points
 * (hundredths of a percent), so no calculation ever meets a float.
 */
final readonly class Percent
{
    private function __construct(private int $basisPoints)
    {
    }

    /**
     * @param string $value e.g. "21", "12.5" or "5.75"
     *
     * @throws \InvalidArgumentException when it is not a number from 0 to 100 with at most two decimals
     */
    public static function fromString(string $value): self
    {
        if (1 !== preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?\z/', $value, $parts)) {
            throw new \InvalidArgumentException('A percentage has digits and at most two decimals.');
        }
        $basisPoints = ((int) $parts[1]) * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
        if ($basisPoints > 10000) {
            throw new \InvalidArgumentException('A percentage is at most 100.');
        }

        return new self($basisPoints);
    }

    public function basisPoints(): int
    {
        return $this->basisPoints;
    }

    /**
     * Always two decimals: "21.00".
     */
    public function decimal(): string
    {
        return \sprintf('%d.%02d', intdiv($this->basisPoints, 100), $this->basisPoints % 100);
    }

    /**
     * The rate as a multiplier: 21 is "0.21".
     */
    public function factorOf(): string
    {
        return $this->factor($this->basisPoints);
    }

    /**
     * What a net amount is multiplied by to get the gross one: 21 is "1.21".
     */
    public function inclusiveFactor(): string
    {
        return $this->factor(10000 + $this->basisPoints);
    }

    public function equals(self $other): bool
    {
        return $this->basisPoints === $other->basisPoints;
    }

    private function factor(int $tenThousandths): string
    {
        $text = \sprintf('%d.%04d', intdiv($tenThousandths, 10000), $tenThousandths % 10000);

        return rtrim(rtrim($text, '0'), '.');
    }
}
