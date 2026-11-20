<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Idoklad\Application;

use Foresi\Idoklad\Application\IdokladSnapshot;
use Foresi\Idoklad\Application\Import\IdokladLinks;
use Foresi\Idoklad\Application\Import\ImportContext;
use Foresi\Idoklad\Domain\ImportMode;
use Foresi\Idoklad\Domain\ImportReport;
use PHPUnit\Framework\TestCase;

final class ImportContextTest extends TestCase
{
    public function testOnlyARunWritesFiles(): void
    {
        self::assertFalse($this->context(ImportMode::Check)->writesFiles());
        self::assertTrue($this->context(ImportMode::Run)->writesFiles());
    }

    private function context(ImportMode $mode): ImportContext
    {
        return new ImportContext('run', $mode, new ImportReport(), self::createStub(IdokladSnapshot::class), self::createStub(IdokladLinks::class), new \DateTimeImmutable());
    }
}
