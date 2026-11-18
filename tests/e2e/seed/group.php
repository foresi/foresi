<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\PartyRole;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatStatus;

foreach (['Example Trading s.r.o.' => '12345695', 'Example International Services and Holding s.r.o.' => '12345687'] as $name => $number) {
    $entityManager->persist(new LegalEntity($name, new RegistrationNumber('CZ', $number), null, $address(1), VatStatus::NotRegistered, ''));
}
$entityManager->flush();
$createParty->createFrom($party(PartyKind::Company, 'Example Partner s.r.o.', [PartyRole::Customer], 'partner@example.com', 1, '12345679'));
