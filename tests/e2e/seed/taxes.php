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
use Foresi\TaxesAndContributions\Contract\StatusKind;
use Foresi\TaxesAndContributions\Domain\OwnerProfile;
use Foresi\TaxesAndContributions\Domain\StatusPeriod;

// The world of the Taxes and contributions spec: the instance's one firm, a sole trader with a main
// activity and no payments yet, and a team member who must not see the page or its menu item, since
// the owner profile holds personal data.
$taxesFirm = new LegalEntity('Example Sole Trader', new RegistrationNumber('CZ', '12345695'), null, $address(3), VatStatus::NotRegistered, 'Example trade register');
$entityManager->persist($taxesFirm);
// A bank account, so the Cash flow page shows its forecast, and a main activity since January, so the
// estimate has advances due and its flows reach the forecast as estimates.
$firmAccount($taxesFirm);
$taxesProfile = new OwnerProfile();
$entityManager->persist($taxesProfile);
$entityManager->persist(new StatusPeriod($taxesProfile, StatusKind::Activity, 'main', new DateTimeImmutable('first day of january this year')));
$taxesMember = new User('taxes-member@example.com', $hash, 'Member Example', 'cs');
$entityManager->persist($taxesMember);
$entityManager->persist(new Membership($taxesMember, Role::Member));
$entityManager->flush();
