<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Contract;

/**
 * A statement file as it was received, for a StatementFormat to read.
 */
final readonly class StatementFile
{
    public const int MAX_NAME = 255;

    /**
     * @param string      $name      the file name it came with, for people to recognise it
     * @param string      $contents  the bytes as received
     * @param string|null $mediaType the media type it came with, when it came with one
     */
    public function __construct(public string $name, public string $contents, public ?string $mediaType = null)
    {
        if ('' === $contents) {
            throw new \InvalidArgumentException('A statement file is not empty.');
        }
    }

    /**
     * The sha256 of the bytes, in lowercase hex: the same file uploaded twice has the same one.
     */
    public function sha256(): string
    {
        return hash('sha256', $this->contents);
    }

    /**
     * The name cut to what is stored.
     */
    public function shortName(): string
    {
        return mb_substr($this->name, 0, self::MAX_NAME);
    }
}
