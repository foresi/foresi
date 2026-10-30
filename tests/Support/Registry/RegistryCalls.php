<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Support\Registry;

/**
 * Counts the calls the fake registers received, so a test can prove that a
 * page did not ask any register.
 */
final class RegistryCalls
{
    public static int $count = 0;

    public static function reset(): void
    {
        self::$count = 0;
    }
}
