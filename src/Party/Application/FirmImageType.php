<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application;

/**
 * The file extension of the image the bytes really are, or null when they are no
 * PNG or JPEG, whatever their name or declared type says.
 */
final class FirmImageType
{
    private const array EXTENSIONS = [\IMAGETYPE_PNG => 'png', \IMAGETYPE_JPEG => 'jpg'];

    public static function of(string $bytes): ?string
    {
        if ('' === $bytes) {
            return null;
        }
        // Reports what it cannot read as a notice as well as by the result.
        $image = @getimagesizefromstring($bytes);
        if (false === $image || $image[0] < 1 || $image[1] < 1) {
            return null;
        }

        return self::EXTENSIONS[$image[2]] ?? null;
    }
}
