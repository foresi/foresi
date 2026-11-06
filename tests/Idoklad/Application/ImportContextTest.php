<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Idoklad\Application;

use App\Idoklad\Application\IdokladSnapshot;
use App\Idoklad\Application\Import\IdokladLinks;
use App\Idoklad\Application\Import\ImportContext;
use App\Idoklad\Domain\ImportMode;
use App\Idoklad\Domain\ImportReport;
use App\Party\Domain\Address;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatStatus;
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
