<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Domain;

enum VatStatus: string
{
    case Registered = 'registered';
    case NotRegistered = 'not_registered';
    case IdentifiedPerson = 'identified_person';

    /**
     * A VAT payer and an identified person show a VAT ID; an unregistered firm has none.
     */
    public function requiresVatId(): bool
    {
        return self::NotRegistered !== $this;
    }

    /**
     * Only a registered payer charges VAT on a domestic supply. An identified
     * person is registered for VAT in other ways but issues without VAT at home.
     */
    public function chargesVat(): bool
    {
        return self::Registered === $this;
    }
}
