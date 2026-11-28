<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Domain;

use Brick\Money\CurrencyType;
use Brick\Money\Exception\UnknownCurrencyException;
use Brick\Money\IsoCurrencyProvider;

/**
 * An ISO 4217 currency, current or historic (ADR 0049).
 *
 * The list comes from brick/money's ISO data, so Money and the list never
 * disagree on minor units. There is one instance per code: Currency::of('EUR')
 * always returns the same object, so `===` compares two currencies, as it did
 * when Currency was an enum.
 */
final class Currency implements \Stringable, \JsonSerializable
{
    /** @var array<string, self> */
    private static array $instances = [];

    /**
     * The ISO code.
     *
     * @deprecated since module API 3.0, removed in 4.0, use code()
     */
    public readonly string $value;

    private function __construct(
        private readonly string $code,
        private readonly int $minorUnits,
        private readonly string $name,
        private readonly ?int $numericCode,
        private readonly bool $historic,
    ) {
        $this->value = $code;
    }

    /**
     * @param string $code the three-letter ISO 4217 code, such as "EUR"
     *
     * @throws UnknownCurrency if ISO 4217 has no such code
     */
    public static function of(string $code): self
    {
        if (isset(self::$instances[$code])) {
            return self::$instances[$code];
        }

        try {
            $iso = IsoCurrencyProvider::getInstance()->getCurrency($code);
        } catch (UnknownCurrencyException $exception) {
            throw new UnknownCurrency(\sprintf('"%s" is not an ISO 4217 currency code.', $code), $exception->getCode(), previous: $exception);
        }

        return self::$instances[$code] = new self(
            $iso->getCurrencyCode(),
            $iso->getDefaultFractionDigits(),
            $iso->getName(),
            $iso->getNumericCode(),
            CurrencyType::IsoHistorical === $iso->getCurrencyType(),
        );
    }

    /**
     * The meaning of the former enum's from(): the currency of the code.
     *
     * @throws \ValueError if ISO 4217 has no such code
     */
    public static function from(string $code): self
    {
        $currency = self::tryFrom($code);
        if (!$currency instanceof Currency) {
            throw new \ValueError(\sprintf('"%s" is not a valid backing value for %s.', $code, self::class));
        }

        return $currency;
    }

    /**
     * The meaning of the former enum's tryFrom(): null for an unknown code.
     */
    public static function tryFrom(?string $code): ?self
    {
        if (null === $code || 1 !== preg_match('/^[A-Z]{3}$/', $code)) {
            return null;
        }

        try {
            return self::of($code);
        } catch (UnknownCurrency) {
            return null;
        }
    }

    public function code(): string
    {
        return $this->code;
    }

    /**
     * Number of decimal digits of the minor unit: 2 for EUR, 0 for JPY, 3 for BHD.
     */
    public function minorUnits(): int
    {
        return $this->minorUnits;
    }

    /**
     * The English ISO name, such as "Euro".
     */
    public function name(): string
    {
        return $this->name;
    }

    public function numericCode(): ?int
    {
        return $this->numericCode;
    }

    /**
     * Whether ISO 4217 lists the code as withdrawn, such as HRK or BGN.
     * A historic code still loads, so old documents keep their currency.
     */
    public function isHistoric(): bool
    {
        return $this->historic;
    }

    public function equals(self $other): bool
    {
        return $this->code === $other->code;
    }

    public function __toString(): string
    {
        return $this->code;
    }

    public function jsonSerialize(): string
    {
        return $this->code;
    }

    private function __clone()
    {
    }
}
