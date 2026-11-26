<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Contract;

/**
 * The cards of a contact's detail that other modules fill, after the billing
 * address, such as what invoices to the contact start with. A provider answers
 * the Ui slot SLOT with items whose template renders one card; the context
 * holds "contactId".
 */
final class ContactCards
{
    public const string SLOT = 'party.contact_cards';
}
