<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Domain;

use Foresi\Invoicing\Contract\DocumentParty;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\Party;

/**
 * Freezes a contact or a firm into the copy a document keeps of it.
 */
final class DocumentParties
{
    public static function ofCustomer(Party $party, Address $billing, ?string $email): DocumentParty
    {
        return new DocumentParty($party->displayName(), $party->registrationNumber()?->toString(), $party->vatId()?->toString(), $billing, '', $email, $party->phone(), $party->website(), null, null);
    }

    public static function ofIssuer(LegalEntity $firm, FirmInvoicing $settings): DocumentParty
    {
        $email = $settings->contactEmail();

        return new DocumentParty(
            $firm->name(),
            $firm->registrationNumber()->toString(),
            $firm->vatId()?->toString(),
            $firm->address(),
            $firm->registerEntry(),
            '' === $email ? null : $email,
            $settings->contactPhone(),
            $settings->contactWeb(),
            $firm->logo(),
            $firm->stamp(),
        );
    }
}
