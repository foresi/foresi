<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Domain;

use Brick\Math\Exception\IntegerOverflowException;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Brick\Money\AllocationMode;
use Brick\Money\Money as BrickMoney;

/**
 * Immutable amount of money in integer minor units. Never uses floats.
 *
 * The brick/money library is an internal detail and is not part of the public API.
 */
final readonly class Money
{
    private function __construct(private BrickMoney $amount, private Currency $currency)
    {
    }

    public static function ofMinor(int $minor, Currency $currency): self
    {
        return new self(BrickMoney::ofMinor($minor, $currency->value), $currency);
    }

    /**
     * @param string $decimal e.g. "1234.50"; rounded HALF_UP to the currency's minor unit
     *
     * @throws \InvalidArgumentException if $decimal is not a number
     * @throws \OverflowException        if the minor-unit amount does not fit a 64-bit integer
     */
    public static function of(string $decimal, Currency $currency): self
    {
        return self::wrap(
            static fn (): BrickMoney => BrickMoney::of($decimal, $currency->value, roundingMode: RoundingMode::HalfUp),
            $currency,
        );
    }

    public function minor(): int
    {
        return $this->amount->getMinorAmount()->toInt();
    }

    public function currency(): Currency
    {
        return $this->currency;
    }

    /**
     * @throws CurrencyMismatch
     * @throws \OverflowException if the result does not fit a 64-bit integer of minor units
     */
    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return self::wrap(fn (): BrickMoney => $this->amount->plus($other->amount), $this->currency);
    }

    /**
     * @throws CurrencyMismatch
     * @throws \OverflowException if the result does not fit a 64-bit integer of minor units
     */
    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return self::wrap(fn (): BrickMoney => $this->amount->minus($other->amount), $this->currency);
    }

    /**
     * @param string $factor e.g. "0.21"; the result is rounded HALF_UP
     *
     * @throws \InvalidArgumentException if $factor is not a number
     * @throws \OverflowException        if the result does not fit a 64-bit integer of minor units
     */
    public function multipliedBy(string $factor): self
    {
        return self::wrap(fn (): BrickMoney => $this->amount->multipliedBy($factor, RoundingMode::HalfUp), $this->currency);
    }

    /**
     * @param string $divisor e.g. "1.21"; the result is rounded HALF_UP to the minor unit
     *
     * @throws \InvalidArgumentException if $divisor is not a number or is zero
     * @throws \OverflowException        if the result does not fit a 64-bit integer of minor units
     */
    public function dividedBy(string $divisor): self
    {
        return self::wrap(fn (): BrickMoney => $this->amount->dividedBy($divisor, RoundingMode::HalfUp), $this->currency);
    }

    /**
     * Rounds to a whole major unit of the currency, HALF_UP: a tie goes away from zero.
     *
     * @throws \OverflowException when the result does not fit
     */
    public function roundedToWholeUnit(): self
    {
        $unit = 10 ** $this->currency->minorUnits();
        $minor = $this->minor();
        if (abs($minor) > \PHP_INT_MAX - $unit) {
            throw new \OverflowException('Amount exceeds the supported range.');
        }
        $units = intdiv(abs($minor) + intdiv($unit, 2), $unit);

        return self::ofMinor(($minor < 0 ? -1 : 1) * $units * $unit, $this->currency);
    }

    /**
     * Splits the amount proportionally to the ratios without losing or gaining a minor unit.
     * Leftover minor units are handed out one by one, starting with the first part with a positive ratio.
     * A part with a zero ratio always receives exactly zero.
     *
     * @return list<self>
     *
     * @throws \InvalidArgumentException when ratios are empty, negative or all zero
     */
    public function allocate(int ...$ratios): array
    {
        $positive = [];
        foreach ($ratios as $ratio) {
            if ($ratio < 0) {
                throw new \InvalidArgumentException('Ratios must not be negative.');
            }
            if ($ratio > 0) {
                $positive[] = $ratio;
            }
        }

        try {
            $shares = $this->amount->allocate($positive, AllocationMode::FloorToFirst);
        } catch (\Brick\Money\Exception\InvalidArgumentException $exception) {
            throw new \InvalidArgumentException($exception->getMessage(), $exception->getCode(), previous: $exception);
        }

        $parts = [];
        $shareIndex = 0;
        foreach ($ratios as $ratio) {
            $parts[] = $ratio > 0
                ? new self($shares[$shareIndex++], $this->currency)
                : self::ofMinor(0, $this->currency);
        }

        return $parts;
    }

    public function isZero(): bool
    {
        return $this->amount->isZero();
    }

    public function isNegative(): bool
    {
        return $this->amount->isNegative();
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->minor() === $other->minor();
    }

    public function format(string $locale): string
    {
        return $this->amount->formatToLocale($locale);
    }

    /**
     * @param \Closure(): BrickMoney $operation
     */
    private static function wrap(\Closure $operation, Currency $currency): self
    {
        try {
            $amount = $operation();
            // Fail here rather than later in minor(): the value must fit BIGINT minor units.
            $amount->getMinorAmount()->toInt();
        } catch (IntegerOverflowException $exception) {
            throw new \OverflowException('Amount exceeds the supported range.', $exception->getCode(), previous: $exception);
        } catch (MathException $exception) {
            throw new \InvalidArgumentException($exception->getMessage(), $exception->getCode(), previous: $exception);
        }

        return new self($amount, $currency);
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new CurrencyMismatch(\sprintf('Cannot combine %s with %s.', $this->currency->value, $other->currency->value));
        }
    }
}
