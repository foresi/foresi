<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Banking\Application;

use Foresi\Banking\Application\PairingRefused;
use Foresi\Tests\Banking\Support\BankingTestCase;

final class PairMovementTest extends BankingTestCase
{
    public function testARefusalSaysWhy(): void
    {
        self::assertSame('The pairing was refused: not_paired.', new PairingRefused(PairingRefused::NOT_PAIRED)->getMessage());
    }
}
