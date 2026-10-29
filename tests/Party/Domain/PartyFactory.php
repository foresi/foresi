<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Party\Domain;

use App\Party\Domain\Party;
use App\Party\Domain\PartyKind;
use App\Tenancy\Domain\TenantId;

/**
 * Builds parties for tests of the address book entities.
 */
final class PartyFactory
{
    public static function company(string $name = 'Example Company', ?TenantId $tenant = null): Party
    {
        return self::stamp(new Party(PartyKind::Company, $name, null, \App\Party\Domain\VatId::fromString('CZ12345687'), 'testing@example.com', ''), $tenant);
    }

    public static function person(string $name = 'Test Person', ?TenantId $tenant = null): Party
    {
        return self::stamp(new Party(PartyKind::Person, $name, null, null, 'testing@example.com', ''), $tenant);
    }

    private static function stamp(Party $party, ?TenantId $tenant): Party
    {
        if ($tenant instanceof TenantId) {
            new \ReflectionProperty($party, 'tenantId')->setValue($party, $tenant);
        }

        return $party;
    }
}
