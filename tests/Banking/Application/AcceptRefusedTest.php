<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Banking\Application;

use Foresi\Banking\Application\AcceptRefused;
use PHPUnit\Framework\TestCase;

final class AcceptRefusedTest extends TestCase
{
    public function testTheMessageNamesTheReason(): void
    {
        $refused = new AcceptRefused(AcceptRefused::NOT_OWNER);

        self::assertSame(AcceptRefused::NOT_OWNER, $refused->reason);
        self::assertSame('The message cannot be recorded anyway: not_owner.', $refused->getMessage());
    }
}
