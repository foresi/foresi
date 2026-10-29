<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Smoke;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class KernelBootsTest extends KernelTestCase
{
    public function testKernelBootsInTestEnv(): void
    {
        $kernel = self::bootKernel();

        // Throws when the Doctrine service is not registered.
        self::getContainer()->get('doctrine');

        self::assertSame('test', $kernel->getEnvironment());
    }
}
