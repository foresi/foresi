<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application;

use App\Party\Domain\Address;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatId;

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
