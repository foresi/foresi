<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Invoicing\Application\UnknownMailVariables;
use PHPUnit\Framework\TestCase;

final class UnknownMailVariablesTest extends TestCase
{
    public function testUnknownVariablesAreNamedOnceEach(): void
    {
        $unknown = new UnknownMailVariables(['numbr', 'link'], ['numbr', 'amount']);

        self::assertSame('Unknown mail variables: numbr, link, amount.', $unknown->getMessage());
    }
}
