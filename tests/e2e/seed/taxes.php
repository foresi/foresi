<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Foresi\Identity\Domain\Membership;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatStatus;

// The world of the Taxes and contributions spec: the instance's one firm, a sole trader, and a team
// member who must not see the page or its menu item, since the owner profile holds personal data.
$entityManager->persist(new LegalEntity('Example Sole Trader', new RegistrationNumber('CZ', '12345695'), null, $address(3), VatStatus::NotRegistered, 'Example trade register'));
$taxesMember = new User('taxes-member@example.com', $hash, 'Member Example', 'cs');
$entityManager->persist($taxesMember);
$entityManager->persist(new Membership($taxesMember, Role::Member));
$entityManager->flush();
