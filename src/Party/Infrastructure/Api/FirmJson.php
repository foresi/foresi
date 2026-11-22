<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Infrastructure\Api;

use Foresi\Party\Contract\VatStretch;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\VatId;

/**
 * The firm of the instance as GET /api/v1/firm and foresi_get_firm write it.
 */
final class FirmJson
{
    /**
     * @param list<VatStretch> $vatStatuses oldest first
     *
     * @return array<string, mixed>
     */
    public static function detail(LegalEntity $firm, array $vatStatuses): array
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
            'vat_statuses' => array_map(self::vatStatus(...), $vatStatuses),
            'register_entry' => $firm->registerEntry(),
            'address' => ['street' => $address->street, 'city' => $address->city, 'postal_code' => $address->postalCode, 'country_code' => $address->countryCode],
        ];
    }

    /**
     * @return array{id: string, status: string, period_length: string|null, valid_from: string|null}
     */
    public static function vatStatus(VatStretch $stretch): array
    {
        return [
            'id' => $stretch->id,
            'status' => $stretch->status->value,
            'period_length' => $stretch->periodLength?->value,
            'valid_from' => $stretch->validFrom?->format('Y-m-d'),
        ];
    }
}
