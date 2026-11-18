<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Party\Domain;

use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\VatId;

/**
 * Builds parties for tests of the address book entities.
 */
final class PartyFactory
{
    public static function company(string $name = 'Example Company'): Party
    {
        return new Party(PartyKind::Company, $name, null, VatId::fromString('CZ12345695'), '');
    }

    public static function person(string $name = 'Test Person'): Party
    {
        return new Party(PartyKind::Person, $name, null, null, '');
    }
}
