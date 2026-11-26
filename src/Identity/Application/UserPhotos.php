<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Application;

/**
 * The people's own photos, outside the web root: one file a person, replaced by
 * a new photo and deleted by removing it. What is stored is never the upload
 * itself but a square of SIDE pixels drawn from it, so no metadata (EXIF, the
 * place it was taken) survives.
 */
interface UserPhotos
{
    /** The largest upload taken, 5 MB. */
    public const int MAX_BYTES = 5 * 1024 * 1024;

    /** The side of the stored square, in pixels. */
    public const int SIDE = 512;

    /**
     * @throws ProfileRefused    when the bytes are no JPEG, PNG or WebP, are too large or have too many pixels
     * @throws \RuntimeException when the photo cannot be written
     */
    public function store(string $userId, string $bytes): void;

    public function remove(string $userId): void;

    /**
     * The stored JPEG; null when the person has none.
     */
    public function path(string $userId): ?string;
}
