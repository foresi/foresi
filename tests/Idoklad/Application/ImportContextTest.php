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
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatStatus;
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
        $firm = new LegalEntity('Example s.r.o.', new RegistrationNumber('CZ', '12345679'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, '');

        return new ImportContext($firm, 'run', $mode, new ImportReport(), self::createStub(IdokladSnapshot::class), self::createStub(IdokladLinks::class), new \DateTimeImmutable());
    }
}
