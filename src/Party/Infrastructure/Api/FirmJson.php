<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Infrastructure\Api;

use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\VatId;

/**
 * The firm of the instance as GET /api/v1/firm and foresi_get_firm write it.
 */
final class FirmJson
{
    /**
     * @return array<string, mixed>
     */
    public static function detail(LegalEntity $firm): array
    {
        $address = $firm->address();
        $vatId = $firm->vatId();

        return [
            'id' => $firm->id(),
            'name' => $firm->name(),
            'registration_country' => $firm->registrationNumber()->countryCode,
            'registration_number' => $firm->registrationNumber()->toString(),
            'vat_id' => $vatId instanceof VatId ? $vatId->toString() : null,
            'vat_status' => $firm->vatStatus()->value,
            'register_entry' => $firm->registerEntry(),
            'address' => ['street' => $address->street, 'city' => $address->city, 'postal_code' => $address->postalCode, 'country_code' => $address->countryCode],
        ];
    }
}
