<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Application;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * Who bears the costs of a supplier by default, from a month on.
 */
#[Operation(
    name: 'margin.supplier_defaults.set',
    method: 'PUT',
    path: '/api/v1/margin/suppliers/{contactId}/default-recipient',
    tool: 'foresi_set_supplier_default_recipient',
    scope: 'margin:write',
    title: 'Set who bears a supplier\'s costs by default',
    description: 'Gives the costs of a supplier, a contact whose invoices the firm receives, to one recipient by default from a month on: "own" for the firm itself, or the id of a contact, a client (find it with foresi_search_contacts). Never a split. '
        .'The default applies to a line that nothing else claims: no mapping of its own, no mapping of its document, no mapping of the planned item its document is paired with. The month of a cost is the month of the occurrence its document is paired with, else the month of the document. '
        .'from_month is written YYYY-MM. A default applies from its month on and earlier months keep their shares; setting a month that already has a default replaces it. recipient null ends the default from that month. The shares of the supplier\'s documents from that month on are recalculated at once. Answers the versions of the default, latest first.',
    response: 'MarginSupplierDefaults',
    staffOnly: true,
)]
final readonly class SetSupplierDefaultInput
{
    public function __construct(
        #[Field('The id of the supplier, a contact.', format: 'uuid')]
        public string $contactId,
        #[Field('"own" for the firm itself, or the id of the contact that bears the cost; null ends the default from the month.', maxLength: 36)]
        public ?string $recipient,
        #[Field('The first month the default applies to, written YYYY-MM, such as "2026-12".', maxLength: 7)]
        public string $fromMonth,
    ) {
    }
}
