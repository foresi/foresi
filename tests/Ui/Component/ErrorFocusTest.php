<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Ui\Component;

use App\Ui\Component\ErrorFocus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ErrorFocus::class)]
final class ErrorFocusTest extends TestCase
{
    public function testTheFirstInvalidControlClaimsTheFocusOnce(): void
    {
        $focus = new ErrorFocus();

        self::assertFalse($focus->claim(false));
        self::assertTrue($focus->claim(true));
        self::assertFalse($focus->claim(true));
        self::assertFalse($focus->claim(false));
    }

    public function testAResetStartsAgain(): void
    {
        $focus = new ErrorFocus();
        $focus->claim(true);

        $focus->reset();

        self::assertTrue($focus->claim(true));
    }
}
