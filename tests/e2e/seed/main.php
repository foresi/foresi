<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\PartyRole;

$inputs = [];
// Named parties the specs rely on.
$inputs[] = $party(PartyKind::Company, 'Example Supplies s.r.o.', [PartyRole::Supplier], 'supplies@example.com', 1, '12345695', 'CZ12345695');
$inputs[] = $party(PartyKind::Person, 'Alex Example', [PartyRole::Partner], 'alex@example.com', 2);
$inputs[] = $party(PartyKind::Person, 'Blair Example', [PartyRole::Partner], 'blair@example.com', 3);
// Long values: truncation with the full value in a title.
$inputs[] = $party(PartyKind::Company, 'Example International Wholesale and Distribution Company Ltd', [PartyRole::Customer, PartyRole::Supplier], 'very.long.address.for.the.purpose.of.testing.truncation@example.com', 4, '00000019');
$inputs[] = $party(PartyKind::Company, 'Example Long Name Manufacturing and Trading Association Group', [PartyRole::Customer], 'long.name@example.com', 5, '00000001');

$prefixes = ['Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon', 'Zeta', 'Eta', 'Theta', 'Iota', 'Kappa'];
for ($i = 6; $i <= 30; ++$i) {
    $prefix = $prefixes[($i - 6) % \count($prefixes)];
    // A sole trader needs no identifier, which keeps the seed free of invented numbers.
    $kind = 0 === $i % 3 ? PartyKind::Person : PartyKind::SoleTrader;
    $roles = match ($i % 4) {
        0 => [PartyRole::Customer],
        1 => [PartyRole::Supplier],
        2 => [PartyRole::Customer, PartyRole::Supplier],
        default => [PartyRole::Partner],
    };
    $name = sprintf('%s Example %02d', $prefix, $i);
    $inputs[] = $party($kind, $name, $roles, sprintf('contact%02d@example.com', $i), $i);
}

foreach ($inputs as $input) {
    $createParty->createFrom($input);
}
