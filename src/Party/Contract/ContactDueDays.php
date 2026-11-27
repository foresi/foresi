<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * How many days after issue an invoice to a contact is due, which
 * PartySummary carries, from the module that keeps it. Without one, or when
 * it knows nothing of the contact, the period is 14 days.
 */
#[AutoconfigureTag(self::TAG)]
interface ContactDueDays
{
    public const string TAG = 'foresi.party.contact_due_days';

    public function dueDaysOf(string $contactId): ?int;
}
