<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Domain;

/**
 * The key and name rules a stage and a lost reason share. A key is a
 * lowercase identifier that never changes; a name is 1 to $max characters,
 * or none for a seeded record with a key, shown under its key's translation.
 */
final class SeededName
{
    public const string KEY_PATTERN = '/^[a-z][a-z0-9_]{0,39}\z/';

    /**
     * @throws InvalidField
     */
    public static function key(?string $key): ?string
    {
        if (null !== $key && 1 !== preg_match(self::KEY_PATTERN, $key)) {
            throw new InvalidField('key', 'key_invalid', 'A key is a lowercase identifier of up to 40 characters, such as "first_call".');
        }

        return $key;
    }

    /**
     * @throws InvalidField
     */
    public static function name(?string $name, ?string $key, int $max): ?string
    {
        if (null === $name) {
            if (null === $key) {
                throw new InvalidField('name', 'name_required', 'Give it a name.');
            }

            return null;
        }
        $name = trim($name);
        if ('' === $name) {
            throw new InvalidField('name', 'name_required', 'Give it a name.');
        }
        if (mb_strlen($name) > $max) {
            throw new InvalidField('name', 'name_too_long', \sprintf('A name is at most %d characters.', $max));
        }

        return $name;
    }
}
