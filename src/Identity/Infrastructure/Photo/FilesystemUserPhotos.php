<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Infrastructure\Photo;

use Foresi\Identity\Application\ProfileRefused;
use Foresi\Identity\Application\UserPhotos;

/**
 * The photos as JPEG files named by the user id. GD redraws every upload: the
 * middle square of the image, turned upright as its EXIF orientation says,
 * scaled to SIDE and laid on white, so a transparent PNG does not turn black.
 * The size is checked before the image is decoded, since decoding takes memory
 * by pixels, not by file size.
 */
final readonly class FilesystemUserPhotos implements UserPhotos
{
    /** About 64 MB once decoded, the most a default memory limit takes; a phone photo of 12 Mpx fits. */
    public const int MAX_PIXELS = 16_000_000;

    private const array TYPES = [\IMAGETYPE_JPEG, \IMAGETYPE_PNG, \IMAGETYPE_WEBP];
    private const int QUALITY = 85;
    private const string USER_ID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

    public function __construct(private string $root)
    {
    }

    public function store(string $userId, string $bytes): void
    {
        $target = $this->file($userId);
        $jpeg = $this->redraw($bytes);

        if (!is_dir($this->root) && !mkdir($this->root, 0o700, true) && !is_dir($this->root)) {
            throw new \RuntimeException('The photo directory cannot be created.');
        }
        // Private from the start, and replaced in one step, so a reader never sees half a photo.
        $temporary = tempnam($this->root, 'photo');
        if (false === $temporary || false === file_put_contents($temporary, $jpeg) || !chmod($temporary, 0o600) || !rename($temporary, $target)) {
            throw new \RuntimeException('The photo cannot be stored.');
        }
    }

    public function remove(string $userId): void
    {
        $file = $this->file($userId);
        if (is_file($file) && !unlink($file)) {
            throw new \RuntimeException('The photo cannot be removed.');
        }
    }

    public function path(string $userId): ?string
    {
        $file = $this->file($userId);

        return is_file($file) ? $file : null;
    }

    private function file(string $userId): string
    {
        if (1 !== preg_match(self::USER_ID, $userId)) {
            throw new \InvalidArgumentException('This is no user id.');
        }

        return $this->root.'/'.$userId.'.jpg';
    }

    private function redraw(string $bytes): string
    {
        if (\strlen($bytes) > self::MAX_BYTES) {
            throw ProfileRefused::photo(ProfileRefused::PHOTO_TOO_LARGE, 'A photo has at most 5 MB.');
        }
        $size = '' === $bytes ? false : @getimagesizefromstring($bytes);
        if (false === $size || !\in_array($size[2], self::TYPES, true) || $size[0] < 1 || $size[1] < 1) {
            throw ProfileRefused::photo(ProfileRefused::PHOTO_TYPE, 'A photo is a JPEG, PNG or WebP image.');
        }
        if ($size[0] * $size[1] > self::MAX_PIXELS) {
            throw ProfileRefused::photo(ProfileRefused::PHOTO_DIMENSIONS, 'A photo has at most 16 million pixels.');
        }
        $image = @imagecreatefromstring($bytes);
        if (false === $image) {
            throw ProfileRefused::photo(ProfileRefused::PHOTO_TYPE, 'A photo is a JPEG, PNG or WebP image.');
        }
        if (\IMAGETYPE_JPEG === $size[2]) {
            $image = $this->upright($image, $bytes);
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $side = min($width, $height);
        $square = imagecreatetruecolor(self::SIDE, self::SIDE);
        imagefill($square, 0, 0, (int) imagecolorallocate($square, 255, 255, 255));
        imagealphablending($square, true);
        imagecopyresampled($square, $image, 0, 0, intdiv($width - $side, 2), intdiv($height - $side, 2), self::SIDE, self::SIDE, $side, $side);

        ob_start();
        imagejpeg($square, null, self::QUALITY);

        return (string) ob_get_clean();
    }

    /**
     * A phone stores a photo as the sensor saw it and says in EXIF how to turn it.
     */
    private function upright(\GdImage $image, string $bytes): \GdImage
    {
        if (!\function_exists('exif_read_data')) {
            return $image;
        }
        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($bytes));
        $orientation = \is_array($exif) ? ($exif['Orientation'] ?? 1) : 1;
        $angle = match (\is_int($orientation) || \is_string($orientation) ? (int) $orientation : 1) {
            3 => 180,
            6 => 270,
            8 => 90,
            default => 0,
        };
        if (0 === $angle) {
            return $image;
        }
        $turned = imagerotate($image, $angle, 0);

        return false === $turned ? $image : $turned;
    }
}
