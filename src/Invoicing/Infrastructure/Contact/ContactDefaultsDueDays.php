<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Contact;

use Foresi\Invoicing\Contract\PartyInvoicingDefaults;
use Foresi\Party\Contract\ContactDueDays;

/**
 * The due period of a contact's invoicing defaults, for the contact's PartySummary.
 */
final readonly class ContactDefaultsDueDays implements ContactDueDays
{
    public function __construct(private PartyInvoicingDefaults $defaults)
    {
    }

    public function dueDaysOf(string $contactId): int
    {
        return $this->defaults->of($contactId)->dueDays;
    }
}
