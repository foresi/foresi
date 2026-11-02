<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

/**
 * The secret part of an inbound address: 128 random bits written as 26
 * characters of lowercase base32 (RFC 4648 alphabet, no padding), safe in the
 * local part of any address. Only its hash is a lookup key.
 */
final class InboundToken
{
    public const string ALPHABET = 'abcdefghijklmnopqrstuvwxyz234567';
    public const int LENGTH = 26;
    private const int BYTES = 16;

    public static function generate(): string
    {
        $bits = '';
        foreach (str_split(random_bytes(self::BYTES)) as $byte) {
            $bits .= str_pad(decbin(\ord($byte)), 8, '0', \STR_PAD_LEFT);
        }
        // 128 bits make 25 full groups of five and three bits left, padded with zeros.
        $bits = str_pad($bits, self::LENGTH * 5, '0');
        $token = '';
        foreach (str_split($bits, 5) as $group) {
            $token .= self::ALPHABET[(int) bindec($group)];
        }

        return $token;
    }

    /**
     * The lookup key of a token: lowercase hex SHA-256 of its lowercase form.
     */
    public static function hash(string $token): string
    {
        return hash('sha256', strtolower($token));
    }
}
