<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Domain;

/**
 * A quantity with four decimals, stored as whole ten-thousandths. The bound keeps
 * a quantity times a percentage factor inside a 64-bit integer.
 */
final readonly class Quantity
{
    private const int LIMIT = 9_999_999_999_999;

    private function __construct(private int $tenThousandths)
    {
    }

    /**
     * @param string $value e.g. "1", "0.5" or "-2.2500"
     *
     * @throws \InvalidArgumentException when it is not a number with at most four decimals, or too large
     */
    public static function fromString(string $value): self
    {
        if (1 !== preg_match('/^(-)?(\d{1,9})(?:\.(\d{1,4}))?\z/', $value, $parts)) {
            throw new \InvalidArgumentException('A quantity has up to nine digits and at most four decimals.');
        }
        $absolute = ((int) $parts[2]) * 10000 + (int) str_pad($parts[3] ?? '', 4, '0');

        return new self('-' === $parts[1] ? -$absolute : $absolute);
    }

    public static function ofTenThousandths(int $tenThousandths): self
    {
        if (abs($tenThousandths) > self::LIMIT) {
            throw new \InvalidArgumentException('The quantity is out of range.');
        }

        return new self($tenThousandths);
    }

    public function tenThousandths(): int
    {
        return $this->tenThousandths;
    }

    public function isZero(): bool
    {
        return 0 === $this->tenThousandths;
    }

    /**
     * Always four decimals: "1.5000".
     */
    public function toDecimal(): string
    {
        $absolute = abs($this->tenThousandths);

        return \sprintf('%s%d.%04d', $this->tenThousandths < 0 ? '-' : '', intdiv($absolute, 10000), $absolute % 10000);
    }
}
