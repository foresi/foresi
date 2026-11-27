<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Contract;

use Foresi\Work\Contract\ProjectTrashSection;
use PHPUnit\Framework\TestCase;

final class ProjectTrashSectionTest extends TestCase
{
    public function testASectionWeighsNothingUnlessGivenAWeight(): void
    {
        self::assertSame(0, new ProjectTrashSection('time', 'Time', [], [], 'token')->weight);
    }
}
