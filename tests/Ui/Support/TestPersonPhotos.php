<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Ui\Support;

use Foresi\Ui\Contract\PersonPhotos;

/**
 * Stands in for the module that keeps the photos: the user "photo-user" has one,
 * nobody else does, so the real ids of the other tests are never answered.
 */
final class TestPersonPhotos implements PersonPhotos
{
    public const string USER = 'photo-user';
    public const string URL = '/people/photo-user/photo?v=1';

    public function url(string $userId): ?string
    {
        return self::USER === $userId ? self::URL : null;
    }
}
