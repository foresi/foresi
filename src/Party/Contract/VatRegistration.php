<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Contract;

/**
 * How the firm is registered for VAT in a stretch: a registered payer, not
 * registered, or an identified person (registered for VAT in other ways, who
 * issues without VAT at home). The values are those of vat_status in the API.
 */
enum VatRegistration: string
{
    case Registered = 'registered';
    case NotRegistered = 'not_registered';
    case IdentifiedPerson = 'identified_person';
}
