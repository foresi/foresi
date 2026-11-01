<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application;

use App\Party\Domain\Address;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatId;
use App\Party\Domain\VatStatus;

/**
 * The checked values of the firm form.
 */
final readonly class LegalEntityInput
{
    /**
     * @param list<string> $bankAccounts IBANs
     */
    public function __construct(
        public string $name,
        public RegistrationNumber $registrationNumber,
        public ?VatId $vatId,
        public Address $address,
        public VatStatus $vatStatus,
        public string $registerEntry,
        public array $bankAccounts,
    ) {
    }
}
