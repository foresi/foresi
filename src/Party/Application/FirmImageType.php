<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application;

/**
 * Decides what uploaded bytes really are, whatever their name or declared type
 * says. A header alone proves nothing, so the image is decoded in full, but only
 * after its size is checked: decoding takes memory by pixels, not by file size.
 * A document must be able to embed it later, so CMYK and anything that does not
 * decode cleanly is refused here rather than when a document is generated.
 */
final class FirmImageType
{
    /** Over 250 mm at 300 dpi, and small enough to decode within a default memory limit. */
    public const int MAX_SIDE = 3000;

    public const string TYPE = 'type';
    public const string DIMENSIONS = 'dimensions';
    public const string COLOR = 'color';

    private const array EXTENSIONS = [\IMAGETYPE_PNG => 'png', \IMAGETYPE_JPEG => 'jpg'];
    private const array JPEG_CHANNELS = [1, 3];
    /** GD decodes a cut-off JPEG without complaint, so its end marker has to be there. */
    private const string JPEG_END = "\xFF\xD9";

    /**
     * The extension of a usable image, or null.
     */
    public static function of(string $bytes): ?string
    {
        return null === self::problem($bytes) ? self::header($bytes)['extension'] ?? null : null;
    }

    /**
     * Why the image cannot be used: TYPE, DIMENSIONS or COLOR; null when it can.
     */
    public static function problem(string $bytes): ?string
    {
        $header = self::header($bytes);
        if (null === $header) {
            return self::TYPE;
        }
        if ($header['width'] > self::MAX_SIDE || $header['height'] > self::MAX_SIDE) {
            return self::DIMENSIONS;
        }
        if ('jpg' === $header['extension'] && !\in_array($header['channels'], self::JPEG_CHANNELS, true)) {
            return self::COLOR;
        }

        if ('jpg' === $header['extension'] && !str_ends_with($bytes, self::JPEG_END)) {
            return self::TYPE;
        }

        return self::decodes($bytes) ? null : self::TYPE;
    }

    /**
     * @return array{extension: string, width: int, height: int, channels: ?int}|null
     */
    private static function header(string $bytes): ?array
    {
        if ('' === $bytes) {
            return null;
        }
        // Reports what it cannot read as a notice as well as by the result.
        $image = @getimagesizefromstring($bytes);
        if (false === $image || $image[0] < 1 || $image[1] < 1 || !isset(self::EXTENSIONS[$image[2]])) {
            return null;
        }

        return ['extension' => self::EXTENSIONS[$image[2]], 'width' => $image[0], 'height' => $image[1], 'channels' => $image['channels'] ?? null];
    }

    private static function decodes(string $bytes): bool
    {
        return false !== @imagecreatefromstring($bytes);
    }
}
