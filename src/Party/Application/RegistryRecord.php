<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Application;

use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;

/**
 * What a public company register says about one subject: enough to prefill
 * a contact form. The address is the registered seat.
 */
final readonly class RegistryRecord
{
    public function __construct(
        public RegistrationNumber $registrationNumber,
        public string $name,
        public ?VatId $vatId,
        public ?Address $seat,
    ) {
    }
}
