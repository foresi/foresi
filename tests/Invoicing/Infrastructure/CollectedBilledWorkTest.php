<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Infrastructure;

use Foresi\Invoicing\Application\InvoiceInput;
use Foresi\Invoicing\Application\IssueRefused;
use Foresi\Invoicing\Infrastructure\CollectedBilledWork;
use PHPUnit\Framework\TestCase;

/**
 * Invoicing runs without a module that bills work: no reference holds, and an
 * input that still names one is refused (ADR 0034, rule 3).
 */
final class CollectedBilledWorkTest extends TestCase
{
    public function testWithoutAProviderNoReferenceHolds(): void
    {
        self::assertNull(new CollectedBilledWork([])->draft('time:01JDEXAMPLE'));
    }

    public function testWithoutAProviderAnOriginIsRefused(): void
    {
        $input = new \ReflectionClass(InvoiceInput::class)->newInstanceWithoutConstructor();

        $this->expectException(IssueRefused::class);
        new CollectedBilledWork([])->origin($input);
    }
}
