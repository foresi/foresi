<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Purchasing\Domain;

use App\Party\Domain\Address;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatStatus;
use App\Purchasing\Domain\EvidenceSeries;
use PHPUnit\Framework\TestCase;

final class EvidenceSeriesTest extends TestCase
{
    public function testTheTenantIsKnownOnlyOncePersisted(): void
    {
        $firm = new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345678'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, '');

        $this->expectException(\LogicException::class);

        EvidenceSeries::standard($firm)->tenantId();
    }
}
