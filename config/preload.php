<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

foreach (glob(dirname(__DIR__).'/var/cache/prod/*.preload.php') ?: [] as $file) {
    require $file;
}
