<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

// The tests run in the application the module is installed in: its environment, its
// kernel and its test support (Foresi\Tests\). The module's own tests are found here.
$application = dirname(__DIR__, 5);
require $application.'/tests/bootstrap.php';
(require $application.'/vendor/autoload.php')->addPsr4('Acme\\Fleet\\Tests\\', __DIR__);
