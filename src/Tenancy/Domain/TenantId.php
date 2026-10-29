<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tenancy\Domain;

/**
 * Identifier of a tenant: a UUID version 7 (time-ordered).
 */
final readonly class TenantId implements \Stringable
{
    private const string PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    private function __construct(private string $value)
    {
    }

    public static function generate(): self
    {
        $now = new \DateTimeImmutable();

        return self::fromParts($now->getTimestamp() * 1000 + (int) $now->format('v'), random_bytes(10));
    }

    /**
     * Builds a UUID v7 from a Unix timestamp in milliseconds and 10 random
     * bytes. Exposed so the layout can be tested deterministically.
     */
    public static function fromParts(int $unixMillis, string $randomBytes): self
    {
        if (10 !== \strlen($randomBytes)) {
            throw new \InvalidArgumentException('Exactly 10 random bytes are required.');
        }

        $bytes = substr(pack('J', $unixMillis), 2).$randomBytes;
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x70);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);

        return new self(sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20),
        ));
    }

    public static function fromString(string $value): self
    {
        $normalised = strtolower($value);
        if (1 !== preg_match(self::PATTERN, $normalised)) {
            throw new \InvalidArgumentException('A tenant id must be a UUID version 7.');
        }

        return new self($normalised);
    }

    public function toString(): string
    {
        return $this->value;
    }

    /**
     * Doctrine needs a string form of an identifier (Tenant::$id) for its identity map.
     */
    public function __toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
