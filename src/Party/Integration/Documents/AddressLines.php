<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Integration\Documents;

use Foresi\Party\Domain\Address;

/**
 * An address line by line as template rows ({% for item in firm.address_lines %}
 * {{ item.line }}): street, postal code with city, country, as the contact
 * directory gives them. Empty parts are left out.
 */
final class AddressLines
{
    /**
     * @return list<array{line: string}>
     */
    public static function of(Address $address): array
    {
        return self::rows([trim($address->street), trim($address->postalCode.' '.$address->city), trim($address->countryCode)]);
    }

    /**
     * @param list<string> $lines
     *
     * @return list<array{line: string}>
     */
    public static function rows(array $lines): array
    {
        return array_values(array_map(static fn (string $line): array => ['line' => $line], array_filter($lines, static fn (string $line): bool => '' !== $line)));
    }
}
