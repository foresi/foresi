<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Events\Infrastructure\Api;

use App\Shared\Infrastructure\Api\Cursor;
use App\Shared\Infrastructure\Api\InvalidParameter;

/**
 * The opaque cursor of the feed: the position of the last event of a slice, as
 * "p:<n>" in base64url without padding. The other lists page by id and their
 * Cursor takes only ids, so the feed has its own; a client passes it back as it
 * got it, and anything else is refused.
 */
final class PositionCursor
{
    public static function encode(int $position): string
    {
        return rtrim(strtr(base64_encode('p:'.$position), '+/', '-_'), '=');
    }

    /**
     * The cursor of the next slice, none when nothing follows.
     */
    public static function next(?int $afterPosition): ?string
    {
        return null === $afterPosition ? null : self::encode($afterPosition);
    }

    /**
     * @return int|null the position to list after; null for the first slice, also for an empty cursor
     *
     * @throws InvalidParameter for a cursor this API did not give
     */
    public static function decode(?string $cursor): ?int
    {
        if (null === $cursor || '' === $cursor) {
            return null;
        }
        $text = base64_decode(strtr($cursor, '-_', '+/'), true);
        if (!\is_string($text) || 1 !== preg_match('/^p:([1-9][0-9]{0,17})$/D', $text, $found) || self::encode((int) $found[1]) !== $cursor) {
            throw new InvalidParameter(Cursor::PARAMETER, 'The cursor is not one this API gave.');
        }

        return (int) $found[1];
    }
}
