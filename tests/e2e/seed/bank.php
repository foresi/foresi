<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatStatus;

// The world of the bank settings spec: a firm with no bank account yet.
$entityManager->persist(new LegalEntity('Example Bank Settings s.r.o.', new RegistrationNumber('CZ', '12345695'), null, $address(4), VatStatus::NotRegistered, ''));
$entityManager->flush();
