<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Deals\Application;

use Foresi\Deals\Application\ClientPersonRefused;
use PHPUnit\Framework\TestCase;

final class ClientPersonRefusedTest extends TestCase
{
    public function testTheMessageNamesEachFieldWithItsReason(): void
    {
        $refused = new ClientPersonRefused(['email' => ClientPersonRefused::EMAIL, 'name' => ClientPersonRefused::REQUIRED]);

        self::assertSame('The contact person is refused: email email_invalid, name required.', $refused->getMessage());
        self::assertSame(['email' => 'email_invalid', 'name' => 'required'], $refused->reasons);
    }
}
