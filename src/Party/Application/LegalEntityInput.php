<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Application;

use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;
use Foresi\Party\Domain\VatStatus;

/**
 * The checked values of the firm form.
 */
final readonly class LegalEntityInput
{
    public function __construct(
        public string $name,
        public RegistrationNumber $registrationNumber,
        public ?VatId $vatId,
        public Address $address,
        public VatStatus $vatStatus,
        public string $registerEntry,
    ) {
    }
}
