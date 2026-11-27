<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Application;

use Foresi\Shared\Application\TaskReferenceSyntax;
use PHPUnit\Framework\TestCase;

final class TaskReferenceSyntaxTest extends TestCase
{
    private const string FIRST = '0198a3c2-7d1e-7a55-9b0c-4f6d2e8a1b33';
    private const string SECOND = '0198a3c2-7d1e-7a55-9b0c-4f6d2e8a1b44';

    public function testTheIdsAreEachOnceInTheOrderOfTheirFirstReferenceAsAList(): void
    {
        $source = \sprintf('- [A](task:%s) and [A again](task:%s) and [B](task:%s)', self::FIRST, strtoupper(self::FIRST), self::SECOND);

        self::assertSame([self::FIRST, self::SECOND], TaskReferenceSyntax::ids($source));
        self::assertSame([], TaskReferenceSyntax::ids('Nothing to see'));
    }
}
