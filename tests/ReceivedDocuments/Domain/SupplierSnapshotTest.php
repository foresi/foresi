<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\ReceivedDocuments\Domain;

use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\ReceivedDocuments\Domain\SupplierSnapshot;
use PHPUnit\Framework\TestCase;

final class SupplierSnapshotTest extends TestCase
{
    public function testALongNameIsCutByCharactersNotBytes(): void
    {
        $party = new Party(PartyKind::Person, str_repeat('é', SupplierSnapshot::MAX_NAME + 45), null, null, '');

        $snapshot = SupplierSnapshot::of($party, new Address('Example Street 2', 'Example City', '110 00', 'CZ'));

        self::assertSame(str_repeat('é', SupplierSnapshot::MAX_NAME), $snapshot->name);
    }
}
