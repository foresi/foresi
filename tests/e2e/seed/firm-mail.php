<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatStatus;

// The world of the e-mail settings spec: the instance's one firm, a non-payer with a bank account
// and no mail server, so the server tab starts empty.
$entityManager->persist($firmAccount(new LegalEntity('Example Small Trader', new RegistrationNumber('CZ', '12345687'), null, $address(2), VatStatus::NotRegistered, 'Example trade register')));
$entityManager->flush();
