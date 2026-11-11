<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Api;

use App\Shared\Domain\Id;

/**
 * The opaque cursor of a list: the id of the last row of a slice, in base64url
 * without padding. A client passes it back as it got it; anything else is refused,
 * so a cursor never lists nothing in silence.
 */
final class Cursor
{
    public const string PARAMETER = 'cursor';

    public static function encode(string $id): string
    {
        return rtrim(strtr(base64_encode($id), '+/', '-_'), '=');
    }

    /**
     * The cursor of the next slice, none when nothing follows.
     */
    public static function next(?string $afterId): ?string
    {
        return null === $afterId ? null : self::encode($afterId);
    }

    /**
     * @return string|null the id to list after; null for the first slice, also for an empty cursor
     *
     * @throws InvalidParameter for a cursor this API did not give
     */
    public static function decode(?string $cursor): ?string
    {
        if (null === $cursor || '' === $cursor) {
            return null;
        }
        $id = base64_decode(strtr($cursor, '-_', '+/'), true);
        if (!\is_string($id) || !Id::isValid($id) || self::encode($id) !== $cursor) {
            throw new InvalidParameter(self::PARAMETER, 'The cursor is not one this API gave.');
        }

        return $id;
    }
}
