<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

/**
 * One-time recovery codes for a lost phone: 16 characters of an alphabet without
 * look-alikes (80 bits), shown in groups of four. Only a SHA-256 hash is kept; a
 * code that random needs no slow hash, and a hash can be looked up directly.
 */
final readonly class RecoveryCodes
{
    public const int COUNT = 10;
    private const string ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    private const int LENGTH = 16;

    /**
     * @return list<string>
     */
    public function generate(): array
    {
        $codes = [];
        while (\count($codes) < self::COUNT) {
            $code = '';
            for ($i = 0; $i < self::LENGTH; ++$i) {
                $code .= self::ALPHABET[random_int(0, \strlen(self::ALPHABET) - 1)];
            }
            $codes[$code] = implode('-', str_split($code, 4));
        }

        return array_values($codes);
    }

    /**
     * Case, spaces and dashes do not matter, as people type codes in many ways.
     */
    public function hash(#[\SensitiveParameter] string $code): string
    {
        return hash('sha256', strtoupper((string) preg_replace('/[\s-]+/', '', $code)));
    }
}
