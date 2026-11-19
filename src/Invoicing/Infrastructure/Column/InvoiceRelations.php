<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Column;

use Foresi\Invoicing\Domain\Invoice;
use Foresi\Party\Contract\Contacts;
use Foresi\Ui\Contract\Relation;
use Foresi\Ui\Contract\RelationSource;

/**
 * The relations of invoices (spec DS6): an invoice's customer, the live contact, whose attributes
 * the invoice lists filter by as "Customer: …" beside the invoice's own copy of it; and a contact's
 * invoices, out of the trash, which the contact list filters by having some, or one so numbered.
 */
final readonly class InvoiceRelations implements RelationSource
{
    public function relations(string $list): iterable
    {
        if (\in_array($list, IssuedDocumentSource::LISTS, true)) {
            return [new Relation('customer', 'invoice.relation.customer', target: Contacts::LIST, on: 'i.customerParty')];
        }
        if (Contacts::LIST === $list) {
            return [new Relation('invoices', 'invoice.relation.invoices', Invoice::class.' ci WHERE ci.customerParty = '.Contacts::ALIAS.' AND ci.deletedAt IS NULL', name: 'ci.number', nameLabel: 'invoice.list.column.number')];
        }

        return [];
    }
}
