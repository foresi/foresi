<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Infrastructure\Http;

use Foresi\Party\Application\FirmImages;
use Foresi\Party\Application\FirmImageType;

/**
 * Stores images as <root>/<name> with mode 0600 (directories 0700). The
 * name comes from the content and the type from the bytes, never from the upload,
 * and a file appears under its name only when it is complete.
 */
final readonly class FilesystemFirmImages implements FirmImages
{
    private const string NAME = '/^[0-9a-f]{64}\.(png|jpg)$/D';

    public function __construct(private string $root)
    {
    }

    public function store(string $bytes): string
    {
        $type = FirmImageType::of($bytes) ?? throw new \InvalidArgumentException('A firm image is a PNG or a JPEG.');
        $name = hash('sha256', $bytes).'.'.$type;
        $directory = $this->root;
        if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new \RuntimeException('The image directory cannot be created.');
        }
        // A directory that already exists may have been created with wider permissions.
        chmod($directory, 0o700);

        $target = $directory.'/'.$name;
        if (is_file($target)) {
            return $name;
        }

        // Private from the start, so the content is never readable by others.
        $temporary = tempnam($directory, 'upload');
        if (false === $temporary || false === file_put_contents($temporary, $bytes) || !chmod($temporary, 0o600) || !rename($temporary, $target)) {
            throw new \RuntimeException('The image cannot be stored.');
        }

        return $name;
    }

    public function path(string $name): string
    {
        if (1 !== preg_match(self::NAME, $name)) {
            throw new \InvalidArgumentException('This is no name of a firm image.');
        }

        // Images stored before the instance had one customer lie under <root>/<tenant>/<name>.
        $path = $this->root.'/'.$name;
        if (is_file($path)) {
            return $path;
        }
        $legacy = glob(addcslashes($this->root, '*?[]\\').'/*/'.$name);
        if (\is_array($legacy) && isset($legacy[0]) && is_file($legacy[0])) {
            return $legacy[0];
        }

        throw new \RuntimeException('The firm image does not exist.');
    }
}
